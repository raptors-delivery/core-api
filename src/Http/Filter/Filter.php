<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Http;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

abstract class Filter
{
    /**
     * The request instance.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The session instance.
     *
     * @var \Illuminate\Contracts\Session\Session
     */
    protected $session;

    /**
     * The builder instance.
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    /**
     * Initialize a new filter instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->session = $request->session();
    }

    /**
     * Apply the filters on the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        foreach ($this->request->all() as $name => $value) {
            $this->applyFilter($name, $value);
        }

        $this->applyRangeFilters();

        if (Http::isInternalRequest($this->request) && method_exists($this, 'queryForInternal')) {
            call_user_func([$this, 'queryForInternal']);
        }

        return $this->builder;
    }

    /**
     * Find dynamically named column filters and apply them.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function applyFilter($name, $value)
    {
        $methodNames = [$name, Str::camel($name)];

        foreach ($methodNames as $methodName) {
            if (method_exists($this, $methodName)) {
                call_user_func_array([$this, $methodName], array_filter([$value]));
                break;
            }
        }
    }

    /**
     * Apply dynamically named range filters
     *
     * @return void
     */
    private function applyRangeFilters()
    {
        $ranges = $this->getRangeFilterCallbacks();

        if (!is_array($ranges)) {
            return;
        }

        foreach ($ranges as $method => $values) {
            if (method_exists($this, $method)) {
                call_user_func_array([$this, $method], $values);
            }
        }
    }

    /**
     * Find standard range filters methods.
     *
     * @return array
     */
    private function getRangeFilterCallbacks(): array
    {
        $ranges = ['after:before', 'from:to', 'min:max', 'start:end', 'gte:lte', 'greater:less'];

        $prepositions = Arr::flatten(
            array_map(
                function ($range) {
                    return explode(':', $range);
                },
                $ranges
            )
        );

        $callbacks = collect($this->request->all())
            ->keys()
            ->filter(
                function ($param) use ($prepositions) {
                    return Str::endsWith($param, $prepositions);
                }
            )->mapWithKeys(
                function ($param) use ($prepositions, $ranges) {
                    $column = Str::replaceLast('_', '', str_replace($prepositions, '', $param));
                    $preposition = Arr::last(explode('_', $param));

                    // find the range
                    $range = Arr::first(
                        $ranges,
                        function ($range) use ($preposition) {
                            return Str::contains($range, $preposition);
                        }
                    );

                    // get values
                    $values = $this->request->all(
                        array_map(
                            function ($preposition) use ($column) {
                                return $column . '_' . $preposition;
                            },
                            explode(':', $range)
                        )
                    );

                    // create callback fn name
                    $callback = Str::camel($column) . 'Between';

                    return [$callback => array_values($values)];
                }
            )
            ->toArray();

        return $callbacks;
    }
}
