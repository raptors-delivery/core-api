<?php

namespace Fleetbase\Traits;

trait ProxiesAuthorizationMethods
{
    /**
     * The relationship to proxy method calls to.
     *
     * @var string
     */
    protected $authorizationRelationship = 'companyUser';

    /**
     * Set the relationship to proxy method calls to.
     */
    public function setAuthorizationRelationship(string $relationship): void
    {
        $this->authorizationRelationship = $relationship;
    }

    /**
     * Handle dynamic method calls and proxy to the specified relationship.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        // Keywords to check in the method name
        $keywords = ['policy', 'policies', 'permission', 'permissions', 'role', 'roles'];

        // Check if the method name contains any of the keywords
        foreach ($keywords as $keyword) {
            if (stripos($method, $keyword) !== false) {
                $this->loadMissing($this->authorizationRelationship);

                // Get the relationship model instance
                $relationshipInstance = $this->{$this->authorizationRelationship};

                // Check if the method exists on the relationship model
                if ($relationshipInstance && method_exists($relationshipInstance, $method)) {
                    return $relationshipInstance->$method(...$parameters);
                }
            }
        }

        // Check if the Expandable trait's __callExpansion method exists
        if (method_exists(static::class, '__callExpansion')) {
            return $this->__callExpansion($method, $parameters);
        }

        // Fallback to the parent class's __call method
        return parent::__call($method, $parameters);
    }
}
