<?php
/**
 * @package    Grav.Common.Page
 *
 * @copyright  Copyright (C) 2014 - 2017 RocketTheme, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Page;

use Grav\Common\Grav;
use Grav\Common\Page\Medium\AbstractMedia;
use Grav\Common\Page\Medium\GlobalMedia;
use Grav\Common\Page\Medium\MediumFactory;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;

class Media extends AbstractMedia
{
    protected static $global;

    protected $path;

    /**
     * @param $path
     */
    public function __construct($path)
    {
        $this->path = $path;

        if (!isset(static::$global)) {
            // Add fallback to global media.
            static::$global = new GlobalMedia($path);
        }

        $this->init();
    }

    /**
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return parent::offsetExists($offset) ?: isset(static::$global[$offset]);
    }

    /**
     * @param mixed $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return parent::offsetGet($offset) ?: static::$global[$offset];
    }

    /**
     * Initialize class.
     */
    protected function init()
    {
        $config = Grav::instance()['config'];
        $exif = Grav::instance()['exif'];

        // Handle special cases where page doesn't exist in filesystem.
        if (!is_dir($this->path)) {
            return;
        }

        $iterator = new \FilesystemIterator($this->path, \FilesystemIterator::UNIX_PATHS | \FilesystemIterator::SKIP_DOTS);

        $media = [];

        /** @var \DirectoryIterator $info */
        foreach ($iterator as $path => $info) {
            // Ignore folders and Markdown files.
            if (!$info->isFile() || $info->getExtension() === 'md' || $info->getBasename()[0] === '.') {
                continue;
            }

            // Find out what type we're dealing with
            list($basename, $ext, $type, $extra) = $this->getFileParts($info->getFilename());

            if ($type === 'alternative') {
                $media["{$basename}.{$ext}"][$type][$extra] = [ 'file' => $path, 'size' => $info->getSize() ];
            } else {
                $media["{$basename}.{$ext}"][$type] = [ 'file' => $path, 'size' => $info->getSize() ];
            }
        }

        foreach ($media as $name => $types) {
            // First prepare the alternatives in case there is no base medium
            if (!empty($types['alternative'])) {
                foreach ($types['alternative'] as $ratio => &$alt) {
                    $alt['file'] = MediumFactory::fromFile($alt['file']);

                    if (!$alt['file']) {
                        unset($types['alternative'][$ratio]);
                    } else {
                        $alt['file']->set('size', $alt['size']);
                    }
                }
            }

            // Create the base medium
            if (empty($types['base'])) {
                if (!isset($types['alternative'])) {
                    continue;
                }
                $max = max(array_keys($types['alternative']));
                $medium = $types['alternative'][$max]['file'];
                $medium = MediumFactory::scaledFromMedium($medium, $max, 1)['file'];
            } else {
                $medium = MediumFactory::fromFile($types['base']['file']);
                $medium && $medium->set('size', $types['base']['size']);
            }

            if (empty($medium)) {
                continue;
            }

            // Read/store Exif metadata as required
            if (!empty($types['base']) && $medium->get('mime') === 'image/jpeg' && empty($types['meta']) && $config->get('system.media.auto_metadata_exif')) {
                $file_path = $types['base']['file'];
                $meta_path = $file_path . '.meta.yaml';
                $meta = $exif->reader->read($file_path);

                if ($meta) {
                    $file = File::instance($meta_path);
                    $file->save(Yaml::dump($meta->getData()));
                    $types['meta']['file'] = $meta_path;
                }
            }

            if (!empty($types['meta'])) {
                $medium->addMetaFile($types['meta']['file']);
            }

            if (!empty($types['thumb'])) {
                // We will not turn it into medium yet because user might never request the thumbnail
                // not wasting any resources on that, maybe we should do this for medium in general?
                $medium->set('thumbnails.page', $types['thumb']['file']);
            }

            // Build missing alternatives
            if (!empty($types['alternative'])) {
                $alternatives = $types['alternative'];
                $max = max(array_keys($alternatives));

                for ($i=$max; $i > 1; $i--) {
                    if (isset($alternatives[$i])) {
                        continue;
                    }

                    $types['alternative'][$i] = MediumFactory::scaledFromMedium($alternatives[$max]['file'], $max, $i);
                }

                foreach ($types['alternative'] as $altMedium) {
                    if ($altMedium['file'] != $medium) {
                        $altWidth = $altMedium['file']->get('width');
                        $medWidth = $medium->get('width');
                        if ($altWidth && $medWidth) {
                            $ratio = $altWidth / $medWidth;
                            $medium->addAlternative($ratio, $altMedium['file']);
                        }
                    }
                }
            }

            $this->add($name, $medium);
        }
    }
}
