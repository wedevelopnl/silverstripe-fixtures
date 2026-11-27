<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;
use WeDevelop\Fixtures\Tests\Unit\ClassSchemas;

#[CoversClass(ClassSchema::class)]
#[CoversClass(FieldKind::class)]
final class ClassSchemaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, FieldKind}>
     */
    public static function keys(): iterable
    {
        yield 'a column' => ['Title', FieldKind::Value];
        yield 'a column of a subclass table' => ['Note', FieldKind::Value];
        yield 'a composite field' => ['Price', FieldKind::Value];
        yield 'a fixed column' => ['ClassName', FieldKind::Fixed];
        yield 'a relation the class resolved' => ['Parent', FieldKind::HasOne];
        yield 'a column a relation also names' => ['Tags', FieldKind::ManyMany];
        yield 'anything else' => ['Subtitle', FieldKind::Unknown];
    }

    #[DataProvider('keys')]
    public function testAKeyIsWhatTheResolvedKindsSayAndOtherwiseAValueWhenTheClassHasSuchAField(string $key, FieldKind $kind): void
    {
        $schema = ClassSchemas::of(
            'App\Sub',
            'Page',
            fields: ['Title' => 'Varchar', 'Price' => 'Money', 'Note' => 'Text', 'Tags' => 'Varchar'],
            fieldTables: ['Note' => 'Page_Sub'],
            kinds: ['Parent' => FieldKind::HasOne, 'Tags' => FieldKind::ManyMany],
            tables: ['Page_Sub'],
        );

        $this->assertSame($kind, $schema->kind($key));
    }
}
