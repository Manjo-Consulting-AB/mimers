<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Issue 16a · Användarfiler. En egen disk, inte local och inte
        // public — `storage_path()` är releasens storage/, på servern en
        // symlänk till shared/storage/, så bytena överlever varje utrullning
        // (issue 16a § Beslut 7). All filhantering går genom Storage-
        // abstraktionen, aldrig genom file_put_contents eller
        // move_uploaded_file ([[ADR-0007 Fillagring hos inleed]]).
        // `throw => true`: en misslyckad skrivning får aldrig bli ett tyst
        // `false` som ändå skapar en databasrad.
        //
        // Issue 651 · Behörigheterna är uttryckliga. Utan `directory_visibility`
        // ärver Flysystem Laravels privata standard och skapar varje
        // underkatalog med `0700`: appen (PHP, kontots användare) når dem, men
        // LiteSpeed, som levererar bytena vid den interna omdirigeringen, gör
        // det inte — och svaret blir LiteSpeeds egen 404 i stället för bytena
        // ([[Pipeline]] § Filleverans). `0711` och inte `0755`: webbservern
        // behöver kunna gå igenom katalogen, inte lista den. Filerna är redan
        // `0644` i dag; raden gör det uttryckligt. Skyddet mot direkt åtkomst är
        // fortfarande `.htaccess`-tillåtlistan på `_protected`, oförändrad.
        'files' => [
            'driver' => 'local',
            'root' => storage_path('files'),
            'visibility' => 'public',
            'directory_visibility' => 'public',
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0711, 'private' => 0700],
            ],
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
