<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;

/**
 * The default sharer: runs every contributor registered via pageflow_share()
 * (i.e. PageflowShares) on each render. The Pageflow Provider binds this as the
 * PageflowSharerContract so the helper works with zero project wiring.
 */
final class RegistryPageflowSharer implements PageflowSharerContract
{
    public function share(Request $request, PageflowResponder $responder): void
    {
        foreach (PageflowShares::all() as $contributor) {
            $contributor($request, $responder);
        }
    }
}
