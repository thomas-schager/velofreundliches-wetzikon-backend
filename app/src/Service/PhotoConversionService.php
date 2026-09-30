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
 */
class PhotoConversionService
{
    private const QUALITY = 82;

    /** @throws RuntimeException if the file can't be read as an image */
    public function convertToWebp(string $sourcePath): string
    {
        try {
            // "[0]" selects just the first frame/page -- guards against a multi-frame source
            // (an animated GIF, or a HEIC "Live Photo" burst) turning into a huge animated WebP.
            $image = new Imagick($sourcePath . '[0]');
            $image->autoOrient(); // reads the EXIF orientation tag -- must run before stripImage()
            $image->stripImage(); // drop EXIF/GPS -- a reporter's photo shouldn't leak their location
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::QUALITY);
            $image->setOption('webp:method', '6'); // slower encode, smaller file -- fine for a one-off at submission time

            return $image->getImageBlob();
        } catch (ImagickException $e) {
            throw new RuntimeException('Could not process image: ' . $e->getMessage(), previous: $e);
        } finally {
            if (isset($image)) {
                $image->clear();
            }
        }
    }
}
