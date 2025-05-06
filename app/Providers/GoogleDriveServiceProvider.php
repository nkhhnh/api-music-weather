<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Google_Client;
use Google\Service\Drive; 
use Illuminate\Support\Facades\Cache;

class GoogleDriveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Drive::class, function ($app) { 
            $config = config('services.google_drive');

            $requiredConfig = ['client_id', 'client_secret', 'refresh_token', 'scopes'];
            foreach ($requiredConfig as $key) {
                if (empty($config[$key])) {
                    throw new \Exception("Missing required Google Drive config: {$key}");
                }
            }

            $client = new Google_Client();
            $client->setClientId($config['client_id']);
            $client->setClientSecret($config['client_secret']);
            $client->setScopes($config['scopes']);
            $client->setAccessType('offline');

            $accessToken = Cache::get('google_drive_access_token');

            if (!$accessToken || !isset($accessToken['access_token'])) {
                try {
                    $accessToken = $client->fetchAccessTokenWithRefreshToken($config['refresh_token']);
                    if (isset($accessToken['error'])) {
                        throw new \Exception('Failed to refresh access token: ' . $accessToken['error'] . ' - ' . ($accessToken['error_description'] ?? 'No description'));
                    }

                    $expiresIn = $accessToken['expires_in'] ?? 3600;
                    Cache::put('google_drive_access_token', $accessToken, now()->addSeconds($expiresIn - 300));
                } catch (\Exception $e) {
                    throw new \Exception('Unable to initialize Google Drive service: ' . $e->getMessage());
                }
            } else {
                $client->setAccessToken($accessToken);

                if ($client->isAccessTokenExpired()) {
                    try {
                        $accessToken = $client->fetchAccessTokenWithRefreshToken($config['refresh_token']);
                        if (isset($accessToken['error'])) {
                            throw new \Exception('Failed to refresh access token: ' . $accessToken['error'] . ' - ' . ($accessToken['error_description'] ?? 'No description'));
                        }

                        $expiresIn = $accessToken['expires_in'] ?? 3600;
                        Cache::put('google_drive_access_token', $accessToken, now()->addSeconds($expiresIn - 300));
                    } catch (\Exception $e) {
                        throw new \Exception('Unable to refresh Google Drive access token: ' . $e->getMessage());
                    }
                }
            }

            $client->setAccessToken($accessToken);

            return new Drive($client); // Sử dụng Drive thay vì Google_Service_Drive
        });
    }

    public function boot(): void
    {
        //
    }
}