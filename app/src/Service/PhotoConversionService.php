<?php

namespace App\Service;

use Imagick;
use ImagickException;
use RuntimeException;

/**
 * Normalizes every uploaded report photo to WebP, whatever the source format (JPEG, PNG, or
 * HEIC/HEIF from Apple's default camera format since iOS 11) -- smaller files than JPEG at
 * equivalent quality, and one stored format instead of format-dependent handling everywhere a
 * photo is read back. Requires the `imagick` PHP extension built with a HEIF delegate (confirmed
 * present on Hostpoint's php85 -- ImageMagick 7 with libheif; see internals.md) -- ext-imagick is
 * a hard composer.json requirement so a missing build fails at install, not at the first upload.
 *
 * Produces two sizes per photo (see convertToWebp()): "large" for the full-screen lightbox,
 * where someone deliberately asked to see more detail, and a much smaller/lower-quality
 * "display" variant for every other context (gallery thumbnails, the "main" preview photo) --
 * those are shown at a few hundred px regardless of how large the source was, so shipping the
 * large variant there was pure waste.
 */
class PhotoConversionService
{
    private const QUALITY_LARGE = 82;
    private const QUALITY_DISPLAY = 70;

    /**
     * Longest-edge cap for the "large" variant, in pixels. Only the full-screen lightbox ever
     * shows this one, capped at 92vw/80vh in every viewer in this project (the public detail
     * popup, the admin gallery) -- on a 2x/retina display that's roughly 2x0.92x a typical
     * ~1600px browser width, so 3000px covers that with headroom even on a large desktop
     * monitor, while phone-camera originals (4032px+ longest edge) are still beyond what any
     * viewer here can actually show. Nothing in this project offers a "download full size"
     * feature, so there's no case that needs more than this.
     */
    private const MAX_DIMENSION_LARGE = 3000;

    /**
     * Longest-edge cap for the "display" variant. Backs every gallery thumbnail and "main" photo
     * preview, none of which render anywhere near this large -- generous headroom for a sharp
     * image at those sizes without approaching the lightbox's own resolution.
     */
    private const MAX_DIMENSION_DISPLAY = 1600;

    /**
     * @return array{large: string, display: string} WebP-encoded image data for both sizes
     * @throws RuntimeException if the file can't be read as an image
     */
    public function convertToWebp(string $sourcePath): array
    {
        $image = null;
        $largeImage = null;

        try {
            // "[0]" selects just the first frame/page -- guards against a multi-frame source
            // (an animated GIF, or a HEIC "Live Photo" burst) turning into a huge animated WebP.
            $image = new Imagick($sourcePath . '[0]');
            $image->autoOrient(); // reads the EXIF orientation tag -- must run before stripImage()
            $image->stripImage(); // drop EXIF/GPS -- a reporter's photo shouldn't leak their location

            // Clone before either resize happens, so the clone still has the full (oriented,
            // stripped) source pixels to work from -- decoding the source once and branching
            // here is cheaper than loading it twice from disk.
            $largeImage = clone $image;
            $large = $this->encodeVariant($largeImage, self::MAX_DIMENSION_LARGE, self::QUALITY_LARGE);
            $display = $this->encodeVariant($image, self::MAX_DIMENSION_DISPLAY, self::QUALITY_DISPLAY);

            return ['large' => $large, 'display' => $display];
        } catch (ImagickException $e) {
            throw new RuntimeException('Could not process image: ' . $e->getMessage(), previous: $e);
        } finally {
            if ($largeImage !== null) {
                $largeImage->clear();
            }
            if ($image !== null) {
                $image->clear();
            }
        }
    }

    private function encodeVariant(Imagick $image, int $maxDimension, int $quality): string
    {
        $this->resizeToMaxDimension($image, $maxDimension);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        $image->setOption('webp:method', '6'); // slower encode, smaller file -- fine for a one-off at submission time

        return $image->getImageBlob();
    }

    /**
     * Downscales in place if the longest edge exceeds $maxDimension; never upscales a
     * smaller-than-cap source. Computed manually rather than via resizeImage()'s own $bestfit
     * flag, which can upscale -- guarding that ourselves here is simpler than relying on exactly
     * how that flag behaves.
     */
    private function resizeToMaxDimension(Imagick $image, int $maxDimension): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $longestEdge = max($width, $height);

        if ($longestEdge <= $maxDimension) {
            return;
        }

        $scale = $maxDimension / $longestEdge;
        $image->resizeImage(
            (int) round($width * $scale),
            (int) round($height * $scale),
            Imagick::FILTER_LANCZOS,
            1,
        );
    }
}
