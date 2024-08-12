<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class MakeService extends Command
{
    protected $signature = 'make:service {name}';
    protected $description = 'Create a new service class';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $name = $this->argument('name');
        $serviceName = Str::studly(class_basename($name)) . 'Service';

        // Replace backslashes with forward slashes in the name argument
        $namePath = Str::replace('\\', '/', $name);

        // Base directory for services
        $directory = app_path('Services/' . dirname($namePath));
        $filePath = "{$directory}/{$serviceName}.php";

        // Ensure the Services directory exists
        if (!File::exists(app_path('Services'))) {
            File::makeDirectory(app_path('Services'), 0755, true);
        }

        if (file_exists($filePath)) {
            $this->error("Service {$serviceName} already exists!");
            return;
        }

        // Create the specific directory if it doesn't exist
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Create the file first
        File::put($filePath, ''); // Creates an empty file

        // Prepare namespace with correct directory structure
        $namespace = 'App\\Services' . (dirname($name) != '.' ? '\\' . str_replace('/', '\\', dirname($name)) : '');

        $serviceTemplate = <<<EOT
        <?php

        namespace {$namespace};

        class {$serviceName}
        {
            public function __construct()
            {
                // 
            }
        }

        EOT;

        // Now write the template into the file
        file_put_contents($filePath, $serviceTemplate);

        $this->info("Service {$serviceName} created successfully.");
    }
}
