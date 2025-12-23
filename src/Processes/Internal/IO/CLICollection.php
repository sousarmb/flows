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
        $this->set($packageVendorAndName = 'rsousa/flows', 'package');
        $this->set($package_vendor_directory = InstalledVersions::getInstallPath($packageVendorAndName), 'package_vendor_directory');
        $this->set($projectDirectory = "{$package_vendor_directory}{$ds}..{$ds}..{$ds}..{$ds}", 'project_directory');
        $this->set("{$projectDirectory}App{$ds}", 'scaffold_destination_directory');
        $this->set("{$package_vendor_directory}{$ds}src{$ds}Scaffold{$ds}App{$ds}", 'scaffold_source_directory');
        $this->set("{$package_vendor_directory}{$ds}src{$ds}Scaffold{$ds}Templates{$ds}", 'scaffold_templates_directory');
    }

    public function getArgv(): array|null
    {
        return $this->get('argv');
    }

    public function getPackage(): string
    {
        return $this->get('package');
    }

    public function getPackageVendorDirectory(): string
    {
        return $this->get('package_vendor_directory');
    }

    public function getScaffoldSourceDirectory(): string
    {
        return $this->get('scaffold_source_directory');
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
