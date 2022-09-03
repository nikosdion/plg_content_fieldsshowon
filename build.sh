#!/usr/bin/env bash
#
# @package   plg_content_fieldsshowon
# @copyright Copyright (c)2022 Nicholas K. Dionysopoulos / Akeeba Ltd
# @license   GNU General Public License version 2, or later; see LICENSE.txt
#

VERSION=$(grep -E '<version>([[:digit:]]+\.?){3}' fieldsshowon.xml | grep -Eo '([[:digit:]]|\.)+')

zip -r plg_content_fieldsshowon-$VERSION.zip * -i language/\* services/\* src/\* xml/\* fieldsshowon.xml