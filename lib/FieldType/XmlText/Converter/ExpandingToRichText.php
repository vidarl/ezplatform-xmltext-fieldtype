<?php

/**
 * This file is part of the eZ Platform XmlText Field Type package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Publish\Core\FieldType\XmlText\Converter;

use DOMElement;

class ExpandingToRichText extends Expanding
{
    /**
     * When converting to RichText we ignore the use of the temporary namespace
     *
     * @param \DOMElement $paragraph
     * @return bool
     */
    protected function isTemporary(DOMElement $paragraph)
    {
        return false;
    }
}
