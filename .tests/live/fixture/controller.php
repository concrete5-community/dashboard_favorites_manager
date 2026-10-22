<?php

namespace Concrete\Package\DfmOrphanFixture;

use Concrete\Core\Package\Package;
use Concrete\Core\Page\Single;

class Controller extends Package
{
    protected $pkgHandle = 'dfm_orphan_fixture';
    protected $appVersionRequired = '9.2.0';
    protected $pkgVersion = '0.0.1';

    public function getPackageName() { return 'DFM disposable orphan fixture'; }
    public function getPackageDescription() { return 'Disposable dashboard pages for uninstall regression tests.'; }
    public function install()
    {
        $pkg = parent::install();
        foreach (['/dashboard/dfm_orphan_fixture', '/dashboard/dfm_orphan_fixture/one', '/dashboard/dfm_orphan_fixture/two'] as $path) {
            $page = Single::add($path, $pkg);
            $page->update(['cName' => 'DFM fixture ' . basename($path)]);
        }
    }
}
