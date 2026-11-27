<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Dev;

use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\State\FixtureTestState as FrameworkFixtureTestState;
use WeDevelop\Fixtures\FixtureLoader;

/**
 * Loads a test class's $fixture_file through the fixture loader and clears with its reset instead of emptying
 * every table. Enable it in project config as the `fixtures` entry of SapphireTestState.States.
 */
class FixtureTestState extends FrameworkFixtureTestState
{
    /**
     * All files of a test class load together, so a record can refer to one in another file.
     */
    protected function loadFixtures(SapphireTest $test): void
    {
        $files = array_filter((array) $test::get_fixture_file());
        $paths = array_values(array_filter($this->getFixturePaths($files, $test), is_string(...)));

        $factory = $this->getFixtureFactory($test::class);
        if ($factory instanceof FixtureFactory && $paths !== []) {
            foreach (FixtureLoader::singleton()->load(...$paths)->toArray() as $class => $identifiers) {
                foreach ($identifiers as $identifier => $id) {
                    $factory->setId($class, $identifier, $id);
                }
            }
        }

        // @phpstan-ignore offsetAccess.nonOffsetAccessible, assign.propertyType (the framework documents its array $loaded as bool)
        $this->loaded[strtolower($test::class)] = true;
    }

    /**
     * @param string $class
     */
    public function tearDownOnce($class): void
    {
        $this->resetFixtureFactory($class);

        // Only a class that used the temporary database loaded anything; never reset the real one
        if (SapphireTest::tempDB()->isUsed()) {
            FixtureLoader::singleton()->reset();
        }
    }
}
