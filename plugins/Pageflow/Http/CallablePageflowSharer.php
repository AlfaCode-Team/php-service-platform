<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;

/**
 * Adapts a closure into a PageflowSharerContract, so a contributor can be a
 * plain callable(Request, PageflowResponder): void instead of a class.
 */
final class CallablePageflowSharer implements PageflowSharerContract
{
    /** @var callable(Request, PageflowResponder): void */
    private $fn;

    /** @param callable(Request, PageflowResponder): void $fn */
    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public function share(Request $request, PageflowResponder $responder): void
    {
        ($this->fn)($request, $responder);
    }
}
