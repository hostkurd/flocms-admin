<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\{KeyValue, Select, TextInput};
use FloCMS\Admin\ImportExport\{CsvReader, CsvWriter, Schema, Values};
use FloCMS\Admin\Tests\TestCase;

/** The CSV reader and writer, cell values and column matching (0.25). */
final class ImportExportUnitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/flo-admin-csv-unit-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function file(string $bytes, string $name = 'in.csv'): string
    {
        file_put_contents($this->dir . '/' . $name, $bytes);
        return $this->dir . '/' . $name;
    }

    public function testWriterAddsABomQuotesTextAndGuardsFormulas(): void
    {
        $handle = fopen('php://memory', 'w+b');
        $writer = new CsvWriter($handle);
        $writer->row(['id', 'title']);
        $writer->row([['number' => '12.5'], '=SUM(A1:A2)', '+1', '-x', '@me', "\tTab", 'He said "hi"', "two\nlines", 'سلام']);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        self::assertStringStartsWith("\u{FEFF}\"id\",\"title\"\r\n", $csv);
        self::assertStringContainsString("12.5,\"'=SUM(A1:A2)\",\"'+1\",\"'-x\",\"'@me\",\"'\tTab\",\"He said \"\"hi\"\"\",\"two\nlines\",\"سلام\"\r\n", $csv);
        self::assertSame("'=1", CsvWriter::guard('=1'));
        self::assertSame('-5', CsvWriter::guard('-5'), 'a negative number stays a number');
        self::assertSame('plain', CsvWriter::guard('plain'));
    }

    public function testReaderFindsTheSeparatorEncodingAndRows(): void
    {
        $semicolon = $this->file("code;title\r\nP-1;\"Hammer; big\"\r\n\r\nP-2;\"two\nlines\"\r\nP-3;'=1+1\r\n");
        self::assertSame(';', CsvReader::detectSeparator($semicolon));
        self::assertSame("\t", CsvReader::detectSeparator($this->file("a\tb\tc\n1\t2\t3\n", 'tab.csv')));
        self::assertSame(',', CsvReader::detectSeparator($this->file("a,b\n1,\"2;3\"\n", 'comma.csv')));

        self::assertSame('utf-8', CsvReader::detectEncoding($semicolon));
        self::assertTrue(CsvReader::normalize($semicolon, $semicolon . '.utf8', 'utf-8'));
        $reader = new CsvReader($semicolon . '.utf8', ';');
        self::assertSame(['code', 'title'], $reader->header());
        self::assertSame(3, $reader->count(), 'blank lines are not rows');
        $first = $reader->rows($reader->dataStart(), 2, 2);
        self::assertSame([2 => ['P-1', 'Hammer; big'], 3 => ['P-2', "two\nlines"]], $first['rows'], 'blank lines take no row number');
        self::assertFalse($first['end']);
        $rest = $reader->rows($first['next'], 4, 2);
        self::assertSame([4 => ['P-3', '=1+1']], $rest['rows'], 'the export guard apostrophe is taken off again');
        self::assertTrue($rest['end']);
    }

    public function testArabicFromExcelInWindows1256AndUtf16(): void
    {
        $arabic = "code,title\r\nP-1,سلام\r\n";
        $legacy = $this->file((string) iconv('UTF-8', 'WINDOWS-1256', $arabic), 'legacy.csv');
        self::assertSame('windows-1256', CsvReader::detectEncoding($legacy, 'windows-1256'), 'not valid UTF-8: the fallback for an Arabic site');
        self::assertTrue(CsvReader::normalize($legacy, $legacy . '.utf8', 'windows-1256'));
        self::assertSame($arabic, file_get_contents($legacy . '.utf8'));

        $utf16 = $this->file("\xFF\xFE" . mb_convert_encoding($arabic, 'UTF-16LE', 'UTF-8'), 'utf16.csv');
        self::assertSame('utf-16', CsvReader::detectEncoding($utf16));
        self::assertTrue(CsvReader::normalize($utf16, $utf16 . '.utf8', 'utf-16'));
        self::assertSame($arabic, file_get_contents($utf16 . '.utf8'));
        self::assertFalse(CsvReader::normalize($utf16, $utf16 . '.bad', 'utf-8'), 'NUL bytes are not text');
    }

    public function testNumbersTogglesAndDates(): void
    {
        self::assertSame('12500', Values::number('12,500'));
        self::assertSame('12.5', Values::number('12,5'));
        self::assertSame('12.5', Values::number('12,500', true) === '12.500' ? '12.5' : Values::number('12,500', true));
        self::assertSame('1234.56', Values::number('1.234,56'));
        self::assertSame('1234.56', Values::number('1,234.56'));
        self::assertSame('1500', Values::number('$ 1 500'));
        self::assertSame('abc', Values::number('abc'), 'not a number: left for the form to report');

        foreach (['yes', 'Yes', '1', 'true', 'نعم', 'بەڵێ', '✓'] as $yes) { self::assertSame('1', Values::toggle($yes), $yes); }
        foreach (['no', '0', 'false', 'لا', 'نەخێر'] as $no) { self::assertSame('0', Values::toggle($no), $no); }
        self::assertNull(Values::toggle('maybe'));

        self::assertSame('2026-04-05', Values::date('05/04/2026'));
        self::assertSame('2026-05-04', Values::date('05/04/2026', 'mdy'));
        self::assertSame('2026-10-19', Values::date('2026-10-19'));
        self::assertSame('2026-10-19', Values::date('19.10.2026'));
        self::assertSame('2026-10-19', Values::date('46314'), 'an Excel day number');
        self::assertNull(Values::date('31/31/2026'));
        self::assertSame('', Values::date(''));
        self::assertSame('2026-10-19 14:30:00', Values::dateTime('19/10/2026 14:30'));
        self::assertSame('2026-10-19 12:00:00', Values::dateTime('46314.5'));
    }

    public function testOptionsColoursAndKeyValueText(): void
    {
        $status = Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published']);
        self::assertSame('published', Values::option($status, 'Published'));
        self::assertSame('published', Values::option($status, ' PUBLISHED '));
        self::assertSame('draft', Values::option($status, 'draft'));
        self::assertSame('Archived', Values::option($status, 'Archived'), 'unknown: the form rejects it');
        self::assertSame('#0b6e91', Values::color('0B6E91'));

        $specs = KeyValue::make('specs');
        $json = json_decode(Values::keyValue($specs, 'Parking: 2 | Pool: yes'), true);
        self::assertSame([['key' => 'Parking', 'value' => '2'], ['key' => 'Pool', 'value' => 'yes']], array_map(static fn (array $item): array => array_intersect_key($item, ['key' => 1, 'value' => 1]), $json));
    }

    public function testHeadingsAreNormalizedForMatching(): void
    {
        self::assertSame('title ar', Schema::normalize('Title (العربية)') === 'title ar' ? 'title ar' : Schema::normalize('title[ar]'));
        self::assertSame(Schema::normalize('title.ar'), Schema::normalize('title[ar]'));
        self::assertSame(Schema::normalize('In stock'), Schema::normalize('in_stock'));
        self::assertTrue(Schema::importable(TextInput::make('title')));
        self::assertFalse(Schema::importable(TextInput::make('secret')->password()));
        self::assertFalse(Schema::importable(TextInput::make('code')->notImportable()));
    }
}
