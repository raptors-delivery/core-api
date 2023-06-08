<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('fleetbase.api.routing.prefix', '/'))->namespace('Fleetbase\Http\Controllers')->group(
    function ($router) {
        $router->get('/', 'Controller@hello');

        /*
        |--------------------------------------------------------------------------
        | Internal Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('fleetbase.api.routing.internal_prefix', 'int'))->namespace('Internal')->group(
            function ($router) {
                $router->prefix('v1')->namespace('v1')->group(
                    function ($router) {
                        $router->fleetbaseAuthRoutes();
                        $router->group(
                            ['prefix' => 'installer'],
                            function ($router) {
                                $router->get('initialize', 'InstallerController@initialize');
                                $router->post('createdb', 'InstallerController@createDatabase');
                                $router->post('migrate', 'InstallerController@migrate');
                                $router->post('seed', 'InstallerController@seed');
                            }
                        );
                        $router->group(
                            ['prefix' => 'onboard'],
                            function ($router) {
                                $router->get('should-onboard', 'OnboardController@shouldOnboard');
                                $router->post('create-account', 'OnboardController@createAccount');
                                $router->post('verify-email', 'OnboardController@verifyEmail');
                                $router->post('send-verification-sms', 'OnboardController@sendVerificationSms');
                                $router->post('send-verification-email', 'OnboardController@sendVerificationEmail');
                            }
                        );
                        $router->group(
                            ['prefix' => 'lookup'],
                            function ($router) {
                                $router->get('whois', 'LookupController@whois');
                                $router->get('currencies', 'LookupController@currencies');
                                $router->get('countries', 'LookupController@countries');
                                $router->get('country/{code}', 'LookupController@country');
                                $router->get('font-awesome-icons', 'LookupController@fontAwesomeIcons');
                            }
                        );
                        $router->group(
                            ['middleware' => ['fleetbase.protected']],
                            function ($router) {
                                $router->fleetbaseRoutes(
                                    'api-credentials',
                                    function ($router, $controller) {
                                        $router->delete('bulk-delete', $controller('bulkDelete'));
                                        $router->patch('roll/{id}', $controller('roll'));
                                        $router->get('export', $controller('export'));
                                    }
                                );
                                $router->fleetbaseRoutes(
                                    'settings',
                                    function ($router, $controller) {
                                        $router->get('overview', $controller('adminOverview'));
                                        $router->get('filesystem-config', $controller('getFilesystemConfig'));
                                        $router->post('filesystem-config', $controller('saveFilesystemConfig'));
                                        $router->get('mail-config', $controller('getMailConfig'));
                                        $router->post('mail-config', $controller('saveMailConfig'));
                                        $router->post('test-mail-config', $controller('testMailConfig'));
                                        $router->get('queue-config', $controller('getQueueConfig'));
                                        $router->post('queue-config', $controller('saveQueueConfig'));
                                        $router->get('services-config', $controller('getServicesConfig'));
                                        $router->post('services-config', $controller('saveServicesConfig'));
                                    }
                                );
                                $router->fleetbaseRoutes('api-events');
                                $router->fleetbaseRoutes('api-request-logs');
                                $router->fleetbaseRoutes(
                                    'webhook-endpoints',
                                    function ($router, $controller) {
                                        $router->get('events', $controller('events'));
                                        $router->get('versions', $controller('versions'));
                                    }
                                );
                                $router->fleetbaseRoutes('webhook-request-logs');
                                $router->fleetbaseRoutes('companies');
                                $router->fleetbaseRoutes(
                                    'users',
                                    function ($router, $controller) {
                                        $router->get('me', $controller('current'));
                                    }
                                );
                                $router->fleetbaseRoutes('user-devices');
                                $router->fleetbaseRoutes('groups');
                                $router->fleetbaseRoutes('roles');
                                $router->fleetbaseRoutes('policies');
                                $router->fleetbaseRoutes('permissions');
                                $router->fleetbaseRoutes('extensions');
                                $router->fleetbaseRoutes('categories');
                                $router->fleetbaseRoutes(
                                    'files',
                                    function ($router, $controller) {
                                        $router->post('upload', $controller('upload'));
                                        $router->post('uploadBase64', $controller('upload-base64'));
                                        $router->get('download/{id}', $controller('download'));
                                    }
                                );
                                $router->fleetbaseRoutes('transactions');
                            }
                        );
                    }
                );
            }
        );
    }
);
