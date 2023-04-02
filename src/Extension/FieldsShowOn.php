<?php
/**
 * @package   plg_content_fieldsshowon
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 2, or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Content\FieldsShowOn\Extension;

defined('_JEXEC') or die;

use DOMElement;
use Exception;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Model\FieldsModel;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;
use ReflectionException;
use SimpleXMLElement;

/**
 * A plugin to add a “show on” attribute to Custom Fields, including subform fields
 *
 * Based on the JT Showon plugin by JoomTools
 *
 * Copyright notice and information of the original plugin (the following 3 lines)
 * Author:    Guido De Gobbis <support@joomtools.de>
 * Copyright: Copyright (c) 2020 JoomTools.de - All rights reserved.
 * License:   GNU General Public License version 3 or later
 *
 * ~~~~~ THIS IS DERIVATIVE WORK, NOT THE ORIGINAL WORK. ~~~~~
 * I (Nicholas K. Dionysopoulos) have heavily modified this plugin to make it more generic and less restrictive.
 *
 * Please do not contact the original author. For any issues with this derivative work please contact the maintainer
 * of this derivative, Nicholas K. Dionysopoulos, through the issues feature of the GitHub repository where this
 * derivative work is hosted or the contact page of my site (https://www.dionysopoulos.me/contact-me.html?view=item).
 *
 * @since 1.0.0
 */
class FieldsShowOn extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Array of custom fields
	 *
	 * @since   1.0.0
	 * @var     array
	 */
	protected static array $itemFields = [];

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @since  1.0.0
	 * @var    boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * Subform field name map.
	 *
	 * The key is the temporary name used in the subform, e.g. "field123". The value is the name we had declared when
	 * defining the custom field and possibly use in showon, e.g. "foobar"
	 *
	 * @since  1.0.0
	 * @var    array
	 */
	protected array $customFieldMap = [];

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * The array keys are event names and the value can be:
	 *
	 *  - The method name to call (priority defaults to 0)
	 *  - An array composed of the method name to call and the priority
	 *
	 * For instance:
	 *
	 *  * array('eventName' => 'methodName')
	 *  * array('eventName' => array('methodName', $priority))
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentPrepareForm'             => 'onContentPrepareForm',
			'onCustomFieldsBeforePrepareField' => 'onCustomFieldsBeforePrepareField',
			'onCustomFieldsPrepareDom'         => ['onCustomFieldsPrepareDom', Priority::MIN],
		];
	}

	/**
	 * Adds the shown property to custom fields.
	 *
	 * This performs triple duty:
	 * - When defining the field ('com_fields.field.*' contexts): lets you define the showon behaviour.
	 * - When using the field in a form ('com_*.*') contexts:
	 *     * applies the `showon` attribute to the form field.
	 *     * reworks the `showon` attributes of subform fields.
	 *
	 * @param   Event  $event  The event we are handling
	 *
	 * @return  void
	 * @throws  ReflectionException
	 * @since   1.0.0
	 */
	public function onContentPrepareForm(Event $event): void
	{
		/**
		 * @var Form              $form
		 * @var array|object|null $data
		 */
		[$form, $data] = $event->getArguments();


		$context            = $form->getName();
		$disabledComponents = $this->params->get('disabled_components', []);

		/**
		 * Part 1: Field Definition
		 *
		 * Add the showon attribute when editing the field definitions in the backend of the site.
		 */
		if (str_starts_with($context, 'com_fields.field.'))
		{
			// Do not use on components the user has explicitly disabled
			foreach ($disabledComponents as $component)
			{
				if (str_starts_with($context, 'com_fields.field.' . $component))
				{
					return;
				}
			}

			$fieldParams = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/xml/showon.xml';
			$showonXml   = new SimpleXMLElement($fieldParams, 0, true);

			$form->setField($showonXml);

			return;
		}

		/**
		 * Part 2: Using The Field
		 *
		 * We process the showon attributes in a loop to address interdependencies.
		 *
		 * For example, field A shows when field B and field C have a value, field C displays when field D has a value.
		 * When we reach field C we decide it must always be hidden because field D has a value different from C's show
		 * on condition AND is always hidden. Therefore, we remove field C from display.
		 *
		 * In the next iteration we reach field B and realise that field C is no longer included in the form. Therefore,
		 * field B must no longer have `showon`.
		 */
		// Do not use on components the user has explicitly disabled
		foreach ($disabledComponents as $component)
		{
			if (str_starts_with($context, $component . '.'))
			{
				return;
			}
		}

		// Get the fieldsets belonging to com_fields
		$fieldSets = $form->getFieldsets('com_fields');

		foreach ($fieldSets as $fieldSetName => $fieldSetInfo)
		{
			$fieldSet = $form->getFieldset($fieldSetInfo->name);

			foreach ($fieldSet as $field)
			{
				$formSource = $field->formsource;

				if (empty($formSource))
				{
					continue;
				}

				// Rework the form source.
				$formSource = $this->reworkFormSource($formSource);

				// The field name is something like "jform[com_fields][tour-dates]", I need "tour-dates"
				$name = $field->name;
				$bits = explode(']', rtrim($name, ']'));
				$name = ltrim(array_pop($bits), '[');

				$form->setFieldAttribute($name, 'formsource', $formSource, 'com_fields');
			}
		}

		$lastSignature = '';

		while (true)
		{
			$displayedFields = [];

			foreach ($fieldSets as $fieldSetInfo)
			{
				$fieldSet = $form->getFieldset($fieldSetInfo->name);

				foreach ($fieldSet as $field)
				{
					$displayedFields[] = $field->fieldname;
					$this->evaluateShowOn($field, $form, (object) $data);
				}
			}

			$thisSignature = md5(implode(':', $displayedFields));

			if ($thisSignature === $lastSignature)
			{
				break;
			}

			$lastSignature = $thisSignature;
		}
	}

	/**
	 * Validates the `showon` value and disables the output of the field if necessary.
	 *
	 * This only applies for DISPLAYING values. For editing purposes the second part of onContentPrepareForm kicks in.
	 *
	 * @param   Event  $event  The event we are handling
	 *
	 * @return  void
	 * @throws  ReflectionException
	 * @since   1.0.0
	 */
	public function onCustomFieldsBeforePrepareField(Event $event): void
	{
		/**
		 * @var  string $context The form context
		 * @var  object $item    The item being edited / displayed
		 * @var  object $field   The current field
		 */
		[$context, $item, $field] = $event->getArguments();

		// Do not use on components the user has explicitly disabled
		$disabledComponents = $this->params->get('disabled_components', []);

		foreach ($disabledComponents as $component)
		{
			if (str_starts_with($context, $component . '.'))
			{
				return;
			}
		}

		$showOnData = trim($field->fieldparams->get('showon', null) ?? '');

		if (empty($showOnData))
		{
			return;
		}

		$itemFields = $this->getCachedFields($context, $item);

		$showon       = [];
		$showon['or'] = explode('[OR]', $showOnData);

		if (!empty($showon['or']))
		{
			foreach ($showon['or'] as $key => $value)
			{
				if (stripos($value, '[AND]') !== false)
				{
					[$or, $and] = explode('[AND]', $value, 2);

					$showon['and']      = explode('[AND]', $and);
					$showon['or'][$key] = $or;
				}
			}
		}

		if (!empty($showon['and']))
		{
			foreach ($showon['and'] as $value)
			{
				[$fieldName, $fieldValue] = explode(':', $value);

				if (empty($itemFields[$fieldName]) || $itemFields[$fieldName]->rawvalue != $fieldValue)
				{
					$field->params->set('display', 0);

					return;
				}
			}
		}

		foreach ($showon['or'] as $value)
		{
			[$fieldName, $fieldValue] = explode(':', $value);

			$showFieldOr[] = (!empty($itemFields[$fieldName]) && $itemFields[$fieldName]->rawvalue == $fieldValue);
		}

		if (!in_array(true, $showFieldOr))
		{
			$field->params->set('display', '0');
		}
	}

	/**
	 * Gets called every time a subform field is created.
	 *
	 * We are deliberately registering this event with the lowest possibly priority to make sure that it gets called
	 * after the subform field plugin has created the DOM element for the subform. That field has two properties,
	 * `name` (the new name Joomla assigned to this custom field) and `fieldname` (the custom field name we declared
	 * when setting up the custom field itself). Therefore we can create a map between the two which we will use in
	 * self::reworkFormSource.
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 * @see     self::reworkFormSource
	 */
	public function onCustomFieldsPrepareDom(Event $event)
	{
		/**
		 * @var  \stdClass  $field  The field
		 * @var  DOMElement $parent The original parent element
		 * @var  Form       $form   The form
		 */

		[$field, $parent, $form] = $event->getArguments();

		// Collect the subform field name mappings (e.g. field123 => foobar)
		if ($field->fieldname ?? '')
		{
			$this->customFieldMap[$field->name] = $field->fieldname;
		}
	}

	/**
	 * Evaluates the showon conditions for a field.
	 *
	 * If all the fields referenced in `showon` are present in the form we take no action. Joomla's client–side code
	 * will take care showing/hiding fields for us.
	 *
	 * If any of the fields referenced in `showon` is missing we take an action depending on whether the `showon`
	 * condition evaluates to show or hide:
	 * - Show: Must always show; we blank out the `showon` attribute.
	 * - Hide: Must always hide; we remove the field.
	 *
	 * @param   FormField  $field  The custom field we are evaluating
	 * @param   Form       $form   The form it belongs to
	 * @param   object     $item   The ticket being edited
	 *
	 * @return  void
	 * @throws  ReflectionException
	 * @since   1.0.0
	 */
	private function evaluateShowOn(FormField $field, Form $form, object $item): void
	{
		$showOnData = trim($field->showon);

		if (empty($showOnData))
		{
			return;
		}

		$itemFields = $this->getCachedFields($form->getName(), $item);

		$showon       = [];
		$showon['or'] = explode('[OR]', $showOnData);

		if (!empty($showon['or']))
		{
			foreach ($showon['or'] as $key => $value)
			{
				if (stripos($value, '[AND]') !== false)
				{
					[$or, $and] = explode('[AND]', $value, 2);

					$showon['and']      = explode('[AND]', $and);
					$showon['or'][$key] = $or;
				}
			}
		}

		$shouldHide   = false;
		$hasAllFields = true;

		if (!empty($showon['and']))
		{
			foreach ($showon['and'] as $value)
			{
				[$fieldName, $fieldValue] = explode(':', $value);

				$hasAllFields = $hasAllFields && $form->getField($fieldName, 'com_fields') !== false;

				if (empty($itemFields[$fieldName]) || $itemFields[$fieldName]->rawvalue != $fieldValue)
				{
					$shouldHide = true;
				}
			}
		}

		foreach ($showon['or'] as $value)
		{
			[$fieldName, $fieldValue] = explode(':', $value);

			$hasAllFields = $hasAllFields && $form->getField($fieldName, 'com_fields') !== false;

			$showFieldOr[] = (!empty($itemFields[$fieldName]) && ($itemFields[$fieldName]->rawvalue ?? null) == $fieldValue);
		}

		$shouldHide = $shouldHide || !in_array(true, $showFieldOr);

		/**
		 * The field has a non–empty shown attribute AND all the fields which control its display are present in the
		 * form.
		 *
		 * We need to let Joomla's `showon` frontend code decide when to show the field. We are not taking any action.
		 */
		if ($hasAllFields)
		{
			return;
		}

		/**
		 * The field needs to be ALWAYS displayed: one of the fields controlling its display is not present in the form.
		 *
		 * We blank out its `showon` attribute.
		 */
		if (!$shouldHide)
		{
			$field->showon = '';

			return;
		}

		/**
		 * The field needs to be ALWAYS hidden: one of the fields controlling its display is not present in the form.
		 *
		 * We remove the field from the form.
		 */
		$form->removeField($field->fieldname, 'com_fields');
	}

	/**
	 * Gets all custom fields for this item.
	 *
	 * This includes fields which are not visible to the user. It is only ever used internally for determining which
	 * fields to display.
	 *
	 * @param   string  $context  The form context e.g. com_foo.bar
	 * @param   object  $item     The content item being edited
	 *
	 * @return  array
	 *
	 * @throws  ReflectionException
	 * @since   1.0.0
	 */
	private function getCachedFields(string $context, object $item): array
	{
		$uniqueItemId = md5($item->id ?? '');

		if (array_key_exists($uniqueItemId, self::$itemFields))
		{
			return self::$itemFields[$uniqueItemId];
		}

		// Pretend it's a backend Super User to force loading all fields, regardless of their access level
		$app = $this->getApplication();

		$refClass = new \ReflectionObject($app);
		$refProp  = $refClass->getProperty('name');
		$refProp->setAccessible(true);
		$appName = $refProp->getValue($app);
		$refProp->setValue($app, 'administrator');

		$user     = $app->getIdentity();
		$refUser  = new \ReflectionObject($user);
		$refProp2 = $refUser->getProperty('isRoot');
		$refProp2->setAccessible(true);
		$isRoot = $refProp2->getValue($user);
		$refProp2->setValue($user, true);

		// Get the private copy of the Fields model from the FieldsHelper
		$refHelper = new \ReflectionClass(FieldsHelper::class);
		$refModel  = $refHelper->getProperty('fieldsCache');
		$refModel->setAccessible(true);
		/** @var FieldsModel $model */
		$model = $refModel->getValue();

		// We need to clear the model's internal cache and query to force it to load all fields
		$refModelObject = new \ReflectionObject($model);
		$refCache       = $refModelObject->getProperty('cache');
		$refCache->setAccessible(true);
		$refCache->setValue($model, []);
		$refQuery = $refModelObject->getProperty('query');
		$refQuery->setAccessible(true);
		$refQuery->setValue($model, null);

		self::$itemFields[$uniqueItemId] = ArrayHelper::pivot(FieldsHelper::getFields($context, $item), 'name');

		$refProp->setValue($app, $appName);
		$refProp2->setValue($user, $isRoot);
		$refCache->setValue($model, []);
		$refQuery->setValue($model, null);

		return self::$itemFields[$uniqueItemId];
	}

	/**
	 * Process the showon attributes in subforms.
	 *
	 * Joomla renames the fields in subforms, e.g. from "foobar" to "field123". Therefore, we need to convert shown
	 * attributes such as "foobar:1" to "field123:1".
	 *
	 * This method takes the raw XML source of the subform and performs these substitutions.
	 *
	 * @param   string  $formSource  The XML of the subform we are reworking.
	 *
	 * @return  string
	 * @throws  Exception
	 * @since   1.0.0
	 */
	private function reworkFormSource(string $formSource): string
	{
		$xml = new SimpleXMLElement($formSource);

		foreach ($xml->children() as $child)
		{
			$node = dom_import_simplexml($child);

			if ($node->hasChildNodes())
			{
				/** @var DOMElement $childNode */
				foreach ($node->childNodes as $childNode)
				{
					if ($childNode->nodeName !== 'form')
					{
						continue;
					}

					$xmlsource  = $this->reworkFormSource($childNode->ownerDocument->saveXML($childNode));
					$xmlDoc     = new SimpleXMLElement($xmlsource);
					$domReplace = dom_import_simplexml($xmlDoc);
					$nodeImport = $childNode->ownerDocument->importNode($domReplace, true);
					$childNode->parentNode->replaceChild($nodeImport, $childNode);
				}
			}

			$showon = $node->getAttribute('showon');

			if (empty($showon))
			{
				continue;
			}

			// TODO Rework showon
			$node->setAttribute('showon', $this->reworkShowOn($showon));
		}

		return $xml->asXML();
	}

	/**
	 * Process the showon attribute for a subform field.
	 *
	 * @param   string  $showon  The raw showon attribute.
	 *
	 * @return  string
	 * @since   1.0.0
	 * @see     self::reworkFormSource()
	 */
	private function reworkShowOn(string $showon): string
	{
		// Split the showon value across '[AND]' and '[OR]'[OR]
		$streamed = [];

		while (str_contains($showon, '[AND]') || str_contains($showon, '[OR]'))
		{
			$andPos    = strpos($showon, '[AND]');
			$orPos     = strpos($showon, '[OR]');
			$delimiter = $andPos > $orPos || $orPos === false ? '[AND]' : '[OR]';
			[$control, $showon] = explode($delimiter, $showon, 2);

			$streamed[] = $control;
		}

		// Remember to add the last bit (or only bit, if there were no boolean operators)
		if (!empty($showon))
		{
			$streamed[] = $showon;
		}

		// Process each showon parameter, as long as it's not a boolean operator
		foreach ($streamed as &$item)
		{
			if (!str_contains($item, ':'))
			{
				continue;
			}

			[$formControl, $condition] = explode(':', $item);

			$suffix      = str_ends_with($formControl, '!') ? '!' : '';
			$formControl = array_search($formControl, $this->customFieldMap) ?: $formControl;
			$item        = $formControl . $suffix . ':' . $condition;
		}

		return implode('', $streamed);
	}
}