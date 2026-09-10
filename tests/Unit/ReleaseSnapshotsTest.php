<?php

use App\Support\ReleaseSnapshots;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->snapshotRoot = sys_get_temp_dir().'/eventi-release-test-'.bin2hex(random_bytes(8));
    mkdir($this->snapshotRoot, 0700);
    $this->makeSnapshot = function (int $day): string {
        $path = $this->snapshotRoot.'/202609'.sprintf('%02d', $day).'-120000-abcdef123456';
        mkdir($path, 0700);
        file_put_contents($path.'/code.tar', 'code');
        file_put_contents($path.'/build.tar.gz', 'assets');

        return $path;
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->snapshotRoot);
});

it('removes the oldest release archives and retains the newest rollback copies', function () {
    $paths = [];
    foreach ([3, 1, 4, 2] as $day) {
        $paths[$day] = ($this->makeSnapshot)($day);
    }

    expect((new ReleaseSnapshots)->prune($this->snapshotRoot, 2))->toBe(2)
        ->and(is_dir($paths[1]))->toBeFalse()
        ->and(is_dir($paths[2]))->toBeFalse()
        ->and(file_get_contents($paths[3].'/code.tar'))->toBe('code')
        ->and(file_get_contents($paths[4].'/build.tar.gz'))->toBe('assets');
});

it('preserves unrelated files, incomplete snapshots and symbolic links', function () {
    $other = ($this->makeSnapshot)(1);
    file_put_contents($other.'/database.sql', 'must remain');
    $incomplete = ($this->makeSnapshot)(2);
    unlink($incomplete.'/build.tar.gz');
    $linked = ($this->makeSnapshot)(3);
    unlink($linked.'/code.tar');
    symlink($other.'/code.tar', $linked.'/code.tar');
    $directoryLink = $this->snapshotRoot.'/20260904-120000-abcdef123456';
    symlink($other, $directoryLink);
    mkdir($this->snapshotRoot.'/bootstrap-original');
    file_put_contents($this->snapshotRoot.'/bootstrap-original/code.tar', 'bootstrap');
    foreach ([5, 6, 7] as $day) {
        ($this->makeSnapshot)($day);
    }

    expect((new ReleaseSnapshots)->prune($this->snapshotRoot, 2))->toBe(1)
        ->and(file_get_contents($other.'/database.sql'))->toBe('must remain')
        ->and(is_file($incomplete.'/code.tar'))->toBeTrue()
        ->and(is_link($linked.'/code.tar'))->toBeTrue()
        ->and(is_link($directoryLink))->toBeTrue()
        ->and(is_file($this->snapshotRoot.'/bootstrap-original/code.tar'))->toBeTrue();
});

it('retains at least two rollback archives even with an invalid retention value', function () {
    foreach ([1, 2, 3] as $day) {
        ($this->makeSnapshot)($day);
    }
    expect((new ReleaseSnapshots)->prune($this->snapshotRoot, 0))->toBe(1);
});
