<?php
/**
 * @version    2.11.x
 * @package    K2
 * @author     JoomlaWorks https://www.joomlaworks.net
 * @copyright  Copyright (c) 2006 - 2022 JoomlaWorks Ltd. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

require_once(JPATH_ADMINISTRATOR . '/components/com_k2/elements/base.php');

class K2ElementK2Tags extends K2Element
{
    public function fetchElement($name, $value, &$node, $control_name)
    {
        $fieldName = $name . '[]';

        $document = Factory::getDocument();
        $document->addStyleSheet('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        $document->addScript('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js');
        $document->addScriptDeclaration('
			$K2(document).ready(function() {
				if(typeof($K2(".k2TagsElement").chosen) == "function") {
					$K2(".k2TagsElement").chosen("destroy");
				}
				$K2(".k2TagsElement").select2({
					width : "300px",
					minimumInputLength : 2,
					ajax: {
						dataType : "json",
						url: "' . URI::root(true) . '/administrator/index.php?option=com_k2&view=item&task=tags&id=1",
						cache: "true",
						 data: function (params) {
						 	var queryParameters = {q: params.term};
						 	return queryParameters;
						 },
						 processResults: function (data) {
						 	var results = [];
						 	jQuery.each(data, function(index, value) {
						 		var row = {
						 			id : value.id,
						 			text : value.name
						 		};
								results.push(row);
						 	});
						 	return {results: results};
						 }

					}
				});
			});
		');

        $options = array();
        if (is_array($value) && count($value)) {
            $db = Factory::getDbo();
            $query = "SELECT id AS value, name AS text FROM #__k2_tags WHERE id IN(" . implode(',', $value) . ")";
            $db->setQuery($query);
            $options = $db->loadObjectList();
        }

        return HTMLHelper::_('select.genericlist', $options, $fieldName, 'class="k2TagsElement" multiple="multiple" size="15"', 'value', 'text', $value);
    }
}

class JFormFieldK2Tags extends K2ElementK2Tags
{
    public $type = 'k2tags';
}

class JElementK2Tags extends K2ElementK2Tags
{
    public $_name = 'k2tags';
}
