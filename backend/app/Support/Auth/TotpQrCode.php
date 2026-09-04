<?php

namespace App\Support\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The enrolment QR code, rendered on the server.
 *
 * # Why the server draws it
 *
 * The obvious alternative is a QR library in the SPA. It is the wrong one for
 * this codebase: the enrolment screen is a lazy route but a QR encoder is a
 * general dependency, and CLAUDE.md Trap 2 classifies a new library appearing
 * in the bundle as class C — the threshold does not move for it, the library
 * comes out. Drawing it here costs one small string on one request and keeps
 * `frontend/`'s initial bundle exactly where it is.
 *
 * # Why SVG in a data URI rather than inline markup
 *
 * The frontend puts it in `<img src>`. Inline SVG in an Angular template needs
 * `bypassSecurityTrustHtml`, which is a sanitizer escape hatch opened for a
 * picture — and once opened, it is opened for whatever else ends up flowing
 * through that binding. An `<img>` renders the same picture with no escape
 * hatch at all.
 */
final class TotpQrCode
{
    private const SIZE = 240;

    private const MARGIN = 2;

    /**
     * `data:image/svg+xml;base64,...` for the given otpauth URI.
     */
    public static function dataUri(string $otpauthUri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($otpauthUri));
    }
}
