<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CategorizePostings;
use App\Mcp\Tools\EditPosting;
use App\Mcp\Tools\GetPosting;
use App\Mcp\Tools\ListBankAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListPostings;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;

class FreyrServer extends Server
{
    /**
     * Tools discoverable via `search_tools`/`execute_tools` rather than
     * `tools/list`, to keep the advertised surface small.
     *
     * @var array<int, class-string<Tool>>
     */
    public const CATALOG_TOOLS = [
        GetPosting::class,
        ListCategories::class,
        ListBankAccounts::class,
        CategorizePostings::class,
        EditPosting::class,
    ];

    protected string $name = 'Freyr';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        Freyr is a self-hosted personal budgeting app. A posting is a bank
        transaction on a bank account; it can carry a confirmed categorization
        (`categorized_subcategory_id`) and/or a machine suggestion
        (`subcategory_id`). Categories are global and each contains
        subcategories; `hints` on a subcategory describe what kind of spending
        belongs there.

        Use `list-postings` to browse and filter transactions. Additional
        tools (posting detail, categories, bank accounts, categorizing and
        editing postings) are discoverable via `search_tools` and callable via
        `execute_tools`. The flagship workflow is categorization: list
        uncategorized postings, inspect the category tree, then categorize in
        bulk.
        MARKDOWN;

    /**
     * @var array<int|string, Tool|class-string<Tool>|array<int, class-string<Tool>>>
     */
    protected array $tools = [
        ListPostings::class,
        ToolSearch::class => self::CATALOG_TOOLS,
    ];
}
