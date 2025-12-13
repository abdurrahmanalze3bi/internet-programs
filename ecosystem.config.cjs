// ecosystem.config.cjs
module.exports = {
    apps: [
        {
            name: 'laravel-8000',
            script: 'C:/wamp64/bin/php/php8.2.13/php.exe', // ⚠️ UPDATE THIS PATH
            args: 'artisan serve --host=127.0.0.1 --port=8000',
            cwd: 'C:/wamp64/www/internet-programs',
            instances: 1,
            autorestart: true,
            watch: false,
            max_memory_restart: '500M',
            env: {
                APP_ENV: 'local',
            },
        },
        {
            name: 'laravel-8001',
            script: 'C:/wamp64/bin/php/php8.2.13/php.exe', // ⚠️ UPDATE THIS PATH
            args: 'artisan serve --host=127.0.0.1 --port=8001',
            cwd: 'C:/wamp64/www/internet-programs',
            instances: 1,
            autorestart: true,
            watch: false,
            max_memory_restart: '500M',
            env: {
                APP_ENV: 'local',
            },
        },
        {
            name: 'laravel-8002',
            script: 'C:/wamp64/bin/php/php8.2.13/php.exe', // ⚠️ UPDATE THIS PATH
            args: 'artisan serve --host=127.0.0.1 --port=8002',
            cwd: 'C:/wamp64/www/internet-programs',
            instances: 1,
            autorestart: true,
            watch: false,
            max_memory_restart: '500M',
            env: {
                APP_ENV: 'local',
            },
        },
        {
            name: 'laravel-8003',
            script: 'C:/wamp64/bin/php/php8.2.13/php.exe', // ⚠️ UPDATE THIS PATH
            args: 'artisan serve --host=127.0.0.1 --port=8003',
            cwd: 'C:/wamp64/www/internet-programs',
            instances: 1,
            autorestart: true,
            watch: false,
            max_memory_restart: '500M',
            env: {
                APP_ENV: 'local',
            },
        },
    ],
};