<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class Setting extends EloquentModel
{
    /**
     * Create a new instance of the model.
     *
     * @param array $attributes The attributes to set on the model.
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->connection = config('fleetbase.db.connection');
    }

    /**
     * No timestamp columns.
     *
     * @var boolean
     */
    public $timestamps = false;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = ['key'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'value' => Json::class
    ];

    /**
     * Finds a system setting.
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function system($key, $defaultValue = null)
    {
        $segments = explode('.', $key);
        $settingKey = 'system.' . $key;
        $queryKey = 'system.' . $segments[0] . '.' . $segments[1];

        if (count($segments) >= 3) {
            // Remove first two segments, join remaining ones
            $subKey = implode('.', array_slice($segments, 2));

            // Query the database for the main setting
            $setting = static::where('key', $queryKey)->first();

            if ($setting) {
                // Get the sub value from the setting value
                return data_get($setting->value, $subKey);
            }
        } else {
            $setting = static::where('key', $settingKey)->first();

            if ($setting) {
                return $setting->value;
            }
        }

        return $defaultValue;
    }

    /**
     * Updates a system setting.
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function configureSystem($key, $value = null)
    {
        return static::configure('system.' . $key, $value);
    }

    /**
     * Updates a system setting.
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function configure($key, $value = null)
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'key' => $key,
                'value' => $value
            ]
        );
    }
}
