<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\Collection;
use Composer\InstalledVersions;

class CLICollection extends Collection
{
    public function __construct()
    {
        parent::__construct();
        $ds = DIRECTORY_SEPARATOR;
        $packageVendorAndName = InstalledVersions::getRootPackage()['name'];
        $this->set($packageVendorAndName, 'package');
        [$vendor, $package] = explode('/', $packageVendorAndName);
        $this->set($vendor, 'package_vendor');
        $this->set($package, 'package_name');
        $this->set($vendorDir = InstalledVersions::getInstallPath($packageVendorAndName), 'package_vendor_directory');
        $this->set("{$vendorDir}src{$ds}Scaffold{$ds}App{$ds}", 'scaffold_source_directory');
        $this->set($rootDirectory = dirname($vendorDir, 7) . $ds, 'root_directory');
        $this->set("{$rootDirectory}App{$ds}", 'scaffold_destination_directory');
        $this->set("{$vendorDir}src{$ds}Scaffold{$ds}Templates{$ds}", 'scaffold_templates_directory');
    }

    public function getArgv(): array|null
    {
        return $this->get('argv');
    }

    public function getPackage(): string
    {
        return $this->get('package');
    }

    public function getPackageVendor(): string
    {
        return $this->get('package_vendor');
    }

    public function getPackageName(): string
    {
        return $this->get('package_name');
    }

    public function getPackageVendorDirectory(): string
    {
        return $this->get('package_vendor_directory');
    }

    public function getScaffoldSourceDirectory(): string
    {
        return $this->get('scaffold_source_directory');
    }

    public function getRootDirectory(): string
    {
        return $this->get('root_directory');
    }

    public function getScaffoldDestinationDirectory(): string
    {
        return $this->get('scaffold_destination_directory');
    }

    public function getScaffoldTemplatesDirectory(): string
    {
        return $this->get('scaffold_templates_directory');
    }
}
