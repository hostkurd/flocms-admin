<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\ImportExport\{CsvWriter, Exporter};
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\{Argument, Definition, Option};

/**
 * php flo admin:export <resource> — a resource's records as CSV (0.25), the same
 * file the list's Export menu downloads, to a file or the screen.
 */
final class ExportCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:export';

    protected string $description = 'Write a resource\'s records as CSV';

    protected string $help = 'php flo admin:export listings --output=listings.csv [--search=villa] [--filter=status:published] [--readable]. Without --output the CSV is printed. The column headings are field names (title.en, title.ar, price …), so the file imports back as it is; --readable writes the labels.';

    protected function configure(Definition $definition): void
    {
        $definition->argument('resource', Argument::REQUIRED, 'The resource\'s slug, e.g. listings');
        $definition->option('output', 'o', Option::VALUE_REQUIRED, 'The file to write (default: the screen)');
        $definition->option('search', null, Option::VALUE_REQUIRED, 'Only records the list\'s search finds');
        $definition->option('filter', null, Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'A list filter as name:value (repeatable)');
        $definition->option('readable', null, Option::VALUE_NONE, 'Labels as headings instead of field names');
    }

    protected function handle(): int
    {
        $this->bootPanel();
        $resource = $this->resourceFor($this->argument('resource'));
        $db = \FloCMS\Core\App::db() ?? $this->fail('No database.');
        $table = $resource->table(new \FloCMS\Admin\Table\Table())->prepare($resource->form(new \FloCMS\Admin\Form\Form()));
        $filters = [];
        foreach ((array) ($this->option('filter') ?? []) as $pair) {
            if (!is_string($pair) || !str_contains($pair, ':')) { $this->invalid('Use --filter=name:value.'); }
            [$name, $value] = explode(':', $pair, 2);
            $filters[trim($name)] = trim($value);
        }
        $filterSet = new \FloCMS\Admin\Table\FilterSet($table, json_encode((object) $filters, JSON_THROW_ON_ERROR));
        if ($filterSet->errors !== []) { $this->fail('Filter not accepted: ' . implode(' ', array_map('strval', $filterSet->errors))); }
        $exporter = new Exporter($resource, $db);
        $search = is_string($this->option('search')) ? $this->option('search') : '';
        $count = $exporter->count($table, $search, $filterSet, null);
        if ($count > $exporter->limit()) { $this->fail($count . ' rows: more than the export limit (' . $exporter->limit() . '). Narrow it with --search or --filter.'); }
        $output = $this->option('output');
        $handle = is_string($output) && $output !== '' ? @fopen($output, 'wb') : fopen('php://stdout', 'wb');
        if (!is_resource($handle)) { $this->fail('Cannot write ' . $output . '.'); }
        $rows = $exporter->write(new CsvWriter($handle), $table, $search, null, null, $filterSet, null, (bool) $this->option('readable'));
        if (is_string($output) && $output !== '') {
            fclose($handle);
            $this->success($rows . ' row' . ($rows === 1 ? '' : 's') . ' written to ' . $output . '.');
        }
        return self::SUCCESS;
    }
}
