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

use Joomla\String\StringHelper;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Document\Feed\FeedEnclosure;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Utility\Utility;

jimport('joomla.application.component.model');

JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_k2/tables');

class K2ModelItem extends K2Model
{
    public function getData()
    {
        $app = Factory::getApplication();
        $id = Factory::getApplication()->input->getInt('id');
        $db = Factory::getDbo();
        $query = "SELECT * FROM #__k2_items WHERE id={$id}";
        $languageFilter = $app->getLanguageFilter();
        if ($languageFilter) {
            $languageTag = Factory::getLanguage()->getTag();
            $query .= " AND language IN (" . $db->Quote($languageTag) . ", " . $db->Quote('*') . ")";
        }
        $db->setQuery($query, 0, 1);
        $row = $db->loadObject();
        return $row;
    }

    public function prepareItem($item, $view, $task)
    {
        jimport('joomla.filesystem.file');
        Table::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR . '/tables');
        $limitstart = Factory::getApplication()->input->getInt('limitstart');
        $app = Factory::getApplication();

        // Initialize params
        if ($view != 'item') {
            $params = $app->getParams('com_k2');
        } else {
            $params = K2HelperUtilities::getParams('com_k2');
        }

        // Category
        $db = Factory::getDbo();
        $category = Table::getInstance('K2Category', 'Table');
        $category->load($item->catid);

        $item->category = $category;
        $item->category->link = urldecode(Route::_(K2HelperRoute::getCategoryRoute($category->id . ':' . urlencode($category->alias))));

        // Read more link
        $link = K2HelperRoute::getItemRoute($item->id . ':' . urlencode($item->alias), $item->catid . ':' . urlencode($item->category->alias));
        $item->link = urldecode(Route::_($link));

        // Print link
        $item->printLink = urldecode(Route::_($link . '&tmpl=component&print=1'));

        // Params
        $cparams = class_exists('JParameter') ? new JParameter($category->params) : new Registry($category->params);
        $iparams = class_exists('JParameter') ? new JParameter($item->params) : new Registry($item->params);
        $item->params = version_compare(PHP_VERSION, '5.0.0', '>=') ? clone $params : $params;

        if ($cparams->get('inheritFrom')) {
            $masterCategoryID = $cparams->get('inheritFrom');
            $masterCategory = Table::getInstance('K2Category', 'Table');
            $masterCategory->load((int)$masterCategoryID);
            $cparams = class_exists('JParameter') ? new JParameter($masterCategory->params) : new Registry($masterCategory->params);
        }
        $item->params->merge($cparams);
        $item->params->merge($iparams);

        // Edit link
        if (K2HelperPermissions::canEditItem($item->created_by, $item->catid)) {
            $item->editLink = Route::_('index.php?option=com_k2&view=item&task=edit&cid=' . $item->id . '&tmpl=component&template=system');
        }

        // Tags
        if (
            ($view == 'item' && ($item->params->get('itemTags') || $item->params->get('itemRelated'))) ||
            ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemTags')) ||
            ($view == 'itemlist' && $task == 'tag' && $item->params->get('tagItemTags')) ||
            ($view == 'itemlist' && $task == 'user' && $item->params->get('userItemTags')) ||
            ($view == 'latest' && $params->get('latestItemTags'))
        ) {
            $tags = $this->getItemTags($item->id);
            for ($i = 0, $iTotal = count($tags); $i < $iTotal; $i++) {
                $tags[$i]->link = Route::_(K2HelperRoute::getTagRoute($tags[$i]->name));
            }
            $item->tags = $tags;
        }

        // Image
        $item->imageXSmall = '';
        $item->imageSmall = '';
        $item->imageMedium = '';
        $item->imageLarge = '';
        $item->imageXLarge = '';

        $imageTimestamp = '';
        $dateModified = ((int)$item->modified) ? $item->modified : '';
        if ($params->get('imageTimestamp', 1) && $dateModified) {
            $imageTimestamp = '?t=' . strftime("%Y%m%d_%H%M%S", strtotime($dateModified));
        }

        $imageFilenamePrefix = md5("Image" . $item->id);
        $imagePathPrefix = Uri::base(true) . '/media/k2/items/cache/' . $imageFilenamePrefix;

        // Check if the "generic" variant exists
        if (File::exists(JPATH_SITE . '/media/k2/items/cache/' . $imageFilenamePrefix . '_Generic.jpg')) {
            $item->imageGeneric = $imagePathPrefix . '_Generic.jpg' . $imageTimestamp;
            $item->imageXSmall = $imagePathPrefix . '_XS.jpg' . $imageTimestamp;
            $item->imageSmall = $imagePathPrefix . '_S.jpg' . $imageTimestamp;
            $item->imageMedium = $imagePathPrefix . '_M.jpg' . $imageTimestamp;
            $item->imageLarge = $imagePathPrefix . '_L.jpg' . $imageTimestamp;
            $item->imageXLarge = $imagePathPrefix . '_XL.jpg' . $imageTimestamp;

            $item->imageProperties = new stdClass;
            $item->imageProperties->filenamePrefix = $imageFilenamePrefix;
            $item->imageProperties->pathPrefix = $imagePathPrefix;
        }

        // Extra fields
        if (
            ($item->params->get('itemExtraFields') && ($view == 'item' || $view == 'relatedByTag')) ||
            ($item->params->get('catItemExtraFields') && $view == 'itemlist' && ($task == '' || $task == 'category')) ||
            ($item->params->get('tagItemExtraFields') && $view == 'itemlist' && $task == 'tag') ||
            ($item->params->get('genericItemExtraFields') && $view == 'itemlist' && ($task == 'search' || $task == 'date'))
        ) {
            $item->extra_fields = $this->getItemExtraFields($item->extra_fields, $item);
        }

        // Attachments
        if (
            ($item->params->get('itemAttachments') && $view == 'item') ||
            ($item->params->get('catItemAttachments') && $view == 'itemlist' && ($task == '' || $task == 'category'))
        ) {
            $item->attachments = $this->getItemAttachments($item->id);
        }

        // Rating
        if (($view == 'item' && $item->params->get('itemRating')) || ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemRating'))) {
            $item->votingPercentage = $this->getVotesPercentage($item->id);
            $item->numOfvotes = $this->getVotesNum($item->id);
        }

        // Filtering
        if ($params->get('introTextCleanup')) {
            $filterTags = preg_split('#[,\s]+#', trim($params->get('introTextCleanupExcludeTags')));
            $filterAttrs = preg_split('#[,\s]+#', trim($params->get('introTextCleanupTagAttr')));
            $filterAttrs = array_filter($filterAttrs);
            $item->introtext = K2HelperUtilities::cleanTags($item->introtext, $filterTags);
            if (isset($filterAttrs) && count($filterAttrs)) {
                $item->introtext = K2HelperUtilities::cleanAttributes($item->introtext, $filterTags, $filterAttrs);
            }
        }

        if ($params->get('fullTextCleanup')) {
            $filterTags = preg_split('#[,\s]+#', trim($params->get('fullTextCleanupExcludeTags')));
            $filterAttrs = preg_split('#[,\s]+#', trim($params->get('fullTextCleanupTagAttr')));
            $filterAttrs = array_filter($filterAttrs);
            $item->fulltext = K2HelperUtilities::cleanTags($item->fulltext, $filterTags);
            if (isset($filterAttrs) && count($filterAttrs)) {
                $item->fulltext = K2HelperUtilities::cleanAttributes($item->fulltext, $filterTags, $filterAttrs);
            }
        }

        if ($item->params->get('catItemIntroTextWordLimit') && $task == 'category') {
            $item->introtext = K2HelperUtilities::wordLimit($item->introtext, $item->params->get('catItemIntroTextWordLimit'));
        }

        $item->rawTitle = $item->title;
        $item->title = htmlspecialchars($item->title, ENT_QUOTES);
        $item->image_caption = htmlspecialchars($item->image_caption, ENT_QUOTES);

        // Author
        if (!empty($item->created_by_alias)) {
            $item->author = new stdClass;
            $item->author->name = $item->created_by_alias;
            $item->author->link = JURI::root();
            $item->author->avatar = K2HelperUtilities::getAvatar('alias');
        } else {
            $author = Factory::getUser($item->created_by);
            $item->author = $author;
            $item->author->link = Route::_(K2HelperRoute::getUserRoute($item->created_by));
            $item->author->avatar = K2HelperUtilities::getAvatar($author->id, $author->email, $params->get('userImageWidth'));
            $item->author->profile = $this->getUserProfile($item->created_by);
        }
        if (empty($item->author->profile)) {
            $item->author->profile = new stdClass;
            $item->author->profile->gender = null;
        }

        // Num of comments
        if ($params->get('comments', 0) > 0) {
            $user = Factory::getUser();
            if (!$user->guest && $user->id == $item->created_by && $params->get('inlineCommentsModeration')) {
                $item->numOfComments = $this->countItemComments($item->id, false);
            } else {
                $item->numOfComments = $this->countItemComments($item->id);
            }
        }

        return $item;
    }

    public function prepareFeedItem(&$item)
    {
        Table::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR . '/tables');
        $params = K2HelperUtilities::getParams('com_k2');
        $limitstart = 0;
        $view = Factory::getApplication()->input->getCmd('view');

        // Import plugins
        PluginHelper::importPlugin('content');
        PluginHelper::importPlugin('k2');
        /* since J4 compatibility */
        /* JDispatcher removed in J4 */
        /*
                $dispatcher = JDispatcher::getInstance();
        */

        // Category
        $category = Table::getInstance('K2Category', 'Table');
        $category->load($item->catid);
        $item->category = $category;

        // Read more link
        $item->link = urldecode(Route::_(K2HelperRoute::getItemRoute($item->id . ':' . $item->alias, $item->catid . ':' . urlencode($item->category->alias))));

        // Filtering
        if ($params->get('introTextCleanup')) {
            $filterTags = preg_split('#[,\s]+#', trim($params->get('introTextCleanupExcludeTags')));
            $filterAttrs = preg_split('#[,\s]+#', trim($params->get('introTextCleanupTagAttr')));
            $filter = new InputFilter($filterTags, $filterAttrs, 0, 1);
            $item->introtext = $filter->clean($item->introtext);
        }

        if ($params->get('fullTextCleanup')) {
            $filterTags = preg_split('#[,\s]+#', trim($params->get('fullTextCleanupExcludeTags')));
            $filterAttrs = preg_split('#[,\s]+#', trim($params->get('fullTextCleanupTagAttr')));
            $filter = new InputFilter($filterTags, $filterAttrs, 0, 1);
            $item->fulltext = $filter->clean($item->fulltext);
        }

        // Description
        $item->description = '';

        // Item image
        if ($params->get('feedItemImage') && File::exists(JPATH_SITE . '/media/k2/items/cache/' . md5("Image" . $item->id) . '_' . $params->get('feedImgSize') . '.jpg')) {
            $altText = ($item->image_caption) ? $item->image_caption : $item->title;
            $item->description .= '<div class="K2FeedImage"><img src="' . JURI::root() . 'media/k2/items/cache/' . md5('Image' . $item->id) . '_' . $params->get('feedImgSize') . '.jpg" alt="' . K2HelperUtilities::cleanHtml($altText) . '" /></div>';

            // Set an image enclosure object
            $item->enclosure = new FeedEnclosure();
            $item->enclosure->url = JURI::root() . 'media/k2/items/cache/' . md5('Image' . $item->id) . '_' . $params->get('feedImgSize') . '.jpg';
            $item->enclosure->length = filesize(JPATH_SITE . '/media/k2/items/cache/' . md5("Image" . $item->id) . '_' . $params->get('feedImgSize') . '.jpg');
            $item->enclosure->type = 'image/jpeg';
        }

        // Item Introtext
        if ($params->get('feedItemIntroText')) {
            // Introtext word limit
            if ($params->get('feedTextWordLimit') && $item->introtext) {
                $item->introtext = K2HelperUtilities::wordLimit($item->introtext, $params->get('feedTextWordLimit'));
            }
            $item->description .= '<div class="K2FeedIntroText">' . $item->introtext . '</div>';
        }

        // Item Fulltext
        if ($params->get('feedItemFullText') && $item->fulltext) {
            $item->description .= '<div class="K2FeedFullText">' . $item->fulltext . '</div>';
        }

        // Item Tags
        $item->tags = array();
        if ($params->get('feedItemTags')) {
            $tags = $this->getItemTags($item->id);
            if (is_array($tags) && count($tags)) {
                foreach ($tags as $tag) {
                    $item->tags[] = '#' . str_replace(' ', '_', $tag->name);
                }
                $item->description .= '<div class="K2FeedTags">' . implode(' ', $item->tags) . '</div>';
                /*
                $item->description .= '<div class="K2FeedTags"><ul>';
                foreach ($tags as $tag) {
                    $item->description .= '<li>'.$tag->name.'</li>';
                }
                $item->description .= '</ul></div>';
                */
            }
        }

        // Item gallery
        if ($params->get('feedItemGallery') && $item->gallery) {
            $params->set('galleries_rootfolder', 'media/k2/galleries');
            $params->set('enabledownload', '0');

            // Create temp object to parse plugins
            $galleryTempText = new stdClass;
            $galleryTempText->text = $item->gallery;
            /* since J4 compatibility */
            Factory::getApplication()->triggerEvent('onContentPrepare', array(
                'com_k2.' . $view . '-gallery',
                &$galleryTempText,
                &$params,
                $limitstart
            ));
            /* since J4 compatibility */
            Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                &$galleryTempText,
                &$params,
                $limitstart
            ));
            $item->description .= '<div class="K2FeedGallery">' . $galleryTempText->text . '</div>';
        }

        // Item Video
        if ($params->get('feedItemVideo') && $item->video) {
            if (!empty($item->video) && StringHelper::substr($item->video, 0, 1) !== '{') {
                $item->description .= '<div class="K2FeedVideo">' . $item->video . '</div>';
            } else {
                $params->set('vfolder', 'media/k2/videos');
                $params->set('afolder', 'media/k2/audio');

                // Create temp object to parse plugins
                $mediaTempText = new stdClass;
                $mediaTempText->text = $item->video;
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onContentPrepare', array(
                    'com_k2.' . $view . '-media',
                    &$mediaTempText,
                    &$params,
                    $limitstart
                ));
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                    &$mediaTempText,
                    &$params,
                    $limitstart
                ));
                $item->description .= '<div class="K2FeedVideo">' . $mediaTempText->text . '</div>';
            }
        }

        // Item attachments
        if ($params->get('feedItemAttachments')) {
            $attachments = $this->getItemAttachments($item->id);
            if (isset($attachments) && count($attachments)) {
                $item->description .= '<div class="K2FeedAttachments"><ul>';
                foreach ($attachments as $attachment) {
                    $item->description .= '<li><a href="' . $attachment->link . '" title="' . K2HelperUtilities::cleanHtml($attachment->titleAttribute) . '">' . $attachment->title . '</a></li>';
                }
                $item->description .= '</ul></div>';
            }
        }

        // Cleanup new lines
        $item->description = preg_replace("#(\r|\n|\r\n)#is", ' ', $item->description);
        $item->description = preg_replace("#(\t|\s+)#is", ' ', $item->description);

        // Author
        if (!empty($item->created_by_alias)) {
            if (!isset($item->author)) {
                $item->author = new stdClass;
            }
            $item->author->name = $item->created_by_alias;
            $item->author->email = '';
        } else {
            $author = Factory::getUser($item->created_by);
            $item->author = $author;
            $item->author->link = Route::_(K2HelperRoute::getUserRoute($item->created_by));
            $item->author->profile = $this->getUserProfile($item->created_by);
        }

        return $item;
    }

    public function prepareJSONItem($item)
    {
        $row = new stdClass;
        $row->id = $item->id;
        $row->title = $item->title;
        $row->alias = $item->alias;
        $row->link = $item->link;
        $row->catid = $item->catid;
        $row->introtext = $item->introtext;
        $row->fulltext = $item->fulltext;
        $row->extra_fields = $item->extra_fields;
        $row->created = $item->created;
        //$row->created_by = $item->created_by;
        $row->created_by_alias = $item->created_by_alias;
        $row->modified = $item->modified;
        //$row->modified_by = $item->modified_by;
        $row->featured = $item->featured;
        //$row->ordering = $item->ordering;
        //$row->featured_ordering = $item->featured_ordering;
        $row->image = (!empty($item->image)) ? $item->image : '';
        $row->imageWidth = (!empty($item->imageWidth)) ? $item->imageWidth : '';
        $row->image_caption = $item->image_caption;
        $row->image_credits = $item->image_credits;
        $row->imageXSmall = $item->imageXSmall;
        $row->imageSmall = $item->imageSmall;
        $row->imageMedium = $item->imageMedium;
        $row->imageLarge = $item->imageLarge;
        $row->imageXLarge = $item->imageXLarge;
        $row->video = $item->video;
        $row->video_caption = $item->video_caption;
        $row->video_credits = $item->video_credits;
        $row->gallery = $item->gallery;
        $row->hits = $item->hits;
        //$row->plugins = $item->plugins;
        $row->category = new stdClass;
        $row->category->id = $item->category->id;
        $row->category->name = $item->category->name;
        $row->category->alias = $item->category->alias;
        $row->category->link = $item->category->link;
        $row->category->description = $item->category->description;
        $row->category->image = $item->category->image;
        $row->category->ordering = $item->category->ordering;
        //$row->category->plugins = $item->category->plugins;
        $row->tags = isset($item->tags) ? $item->tags : array();
        $row->attachments = isset($item->attachments) ? $item->attachments : array();
        $row->votingPercentage = isset($item->votingPercentage) ? $item->votingPercentage : '';
        $row->numOfvotes = isset($item->numOfvotes) ? $item->numOfvotes : '';
        if (isset($item->author)) {
            $row->author = new stdClass;
            //$row->author->id = $item->author->id;
            $row->author->name = $item->author->name;
            //$row->author->username = $item->author->username;
            $row->author->link = $item->author->link;
            $row->author->avatar = $item->author->avatar;
            if (isset($item->author->profile)) {
                unset($item->author->profile->plugins);
            }
            $row->author->profile = $item->author->profile;
            if (isset($row->author->profile->url)) {
                $row->author->profile->url = htmlspecialchars($row->author->profile->url, ENT_QUOTES, 'UTF-8');
            }
        }
        $row->numOfComments = (!empty($item->numOfComments)) ? $item->numOfComments : null;
        $row->events = $item->event;
        $row->language = $item->language;
        return $row;
    }

    public function execPlugins($item, $view, $task)
    {
        jimport('joomla.filesystem.file');
        jimport('joomla.filesystem.folder');
        $params = K2HelperUtilities::getParams('com_k2');
        $limitstart = Factory::getApplication()->input->getInt('limitstart');

        // Import plugins
        PluginHelper::importPlugin('content');
        PluginHelper::importPlugin('k2');
        /* since J4 compatibility */
        /* JDispatcher removed in J4 */
        /*
                $dispatcher = JDispatcher::getInstance();
        */

        if (!isset($this->isSigInstalled)) {
            $this->isSigInstalled = (
                File::exists(JPATH_SITE . '/plugins/content/jw_sigpro.php') ||
                File::exists(JPATH_SITE . '/plugins/content/jw_sigpro/jw_sigpro.php') ||
                File::exists(JPATH_SITE . '/plugins/content/jw_sigpro/jw_sigpro/jw_sigpro.php')
            );
        }

        if (!$this->isSigInstalled) {
            $item->gallery = null;
        }

        // Gallery
        if (($view == 'item' && $item->params->get('itemImageGallery')) || ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemImageGallery')) || ($view == 'relatedByTag')) {
            if ($item->gallery) {
                if (StringHelper::strpos($item->gallery, 'flickr.com') === false) {
                    $item->gallery = "{gallery}{$item->id}{/gallery}";
                    if (!Folder::exists(JPATH_SITE . '/media/k2/galleries/' . $item->id)) {
                        $item->gallery = null;
                    }
                }
                $params->set('galleries_rootfolder', 'media/k2/galleries');

                if ($view == 'item') {
                    $width = (int)$item->params->get('itemImageGalleryWidth');
                    $height = (int)$item->params->get('itemImageGalleryHeight');
                } else {
                    $width = (int)$item->params->get('catItemImageGalleryWidth');
                    $height = (int)$item->params->get('catItemImageGalleryHeight');
                }

                if ($width && $height) {
                    if (StringHelper::strpos($item->gallery, 'flickr.com') !== false) {
                        $sigParams = ComponentHelper::getParams('com_sigpro');
                        $item->gallery = str_replace('{/gallery}', ':' . $sigParams->get('flickrImageCount', 20) . ':' . $width . ':' . $height . '{/gallery}', $item->gallery);
                    } else {
                        $item->gallery = str_replace('{/gallery}', ':' . $width . ':' . $height . '{/gallery}', $item->gallery);
                    }
                }

                // Create temp object to parse plugins
                $galleryTempText = new stdClass;
                $galleryTempText->text = $item->gallery;
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onContentPrepare', array(
                    'com_k2.' . $view . '-gallery',
                    &$galleryTempText,
                    &$params,
                    $limitstart
                ));
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                    &$galleryTempText,
                    &$params,
                    $limitstart
                ));
                $item->gallery = $galleryTempText->text;
            }
        }

        // Media (also referred to as "Video" in variables)
        if (($view == 'item' && $item->params->get('itemVideo')) || ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemVideo')) || ($view == 'latest' && $item->params->get('latestItemVideo')) || ($view == 'relatedByTag')) {
            if (!empty($item->video) && StringHelper::substr($item->video, 0, 1) !== '{') {
                $item->video = $item->video;
                $item->videoType = 'embedded';
            } else {
                $item->videoType = 'allvideos';
                $params->set('afolder', 'media/k2/audio');
                $params->set('vfolder', 'media/k2/videos');

                if ($view == 'item') {
                    $params->set('vwidth', $item->params->get('itemVideoWidth'));
                    $params->set('vheight', $item->params->get('itemVideoHeight'));
                    $params->set('autoplay', $item->params->get('itemVideoAutoPlay'));
                } elseif ($view == 'latest') {
                    $params->set('vwidth', $item->params->get('latestItemVideoWidth'));
                    $params->set('vheight', $item->params->get('latestItemVideoHeight'));
                    $params->set('autoplay', $item->params->get('latestItemVideoAutoPlay'));
                } else {
                    $params->set('vwidth', $item->params->get('catItemVideoWidth'));
                    $params->set('vheight', $item->params->get('catItemVideoHeight'));
                    $params->set('autoplay', $item->params->get('catItemVideoAutoPlay'));
                }

                // Create temp object to parse plugins
                $mediaTempText = new stdClass;
                $mediaTempText->text = $item->video;
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onContentPrepare', array(
                    'com_k2.' . $view . '-media',
                    &$mediaTempText,
                    &$params,
                    $limitstart
                ));
                /* since J4 compatibility */
                Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                    &$mediaTempText,
                    &$params,
                    $limitstart
                ));
                $item->video = $mediaTempText->text;
            }
        }

        // Plugins
        $item->text = '';
        $params->set('vfolder', null);
        $params->set('afolder', null);
        $params->set('vwidth', null);
        $params->set('vheight', null);
        $params->set('autoplay', null);
        $params->set('galleries_rootfolder', null);
        $params->set('enabledownload', null);

        if ($view == 'item') {
            if ($item->params->get('itemIntroText')) {
                $item->text .= $item->introtext;
            }
            if ($item->params->get('itemFullText')) {
                $item->text .= '{K2Splitter}' . $item->fulltext;
            }
        } elseif ($view == 'latest') {
            if ($item->params->get('latestItemIntroText')) {
                $item->text .= $item->introtext;
            }
        } else {
            switch ($task) {
                case '':
                case 'category':
                    if ($item->params->get('catItemIntroText')) {
                        $item->text .= $item->introtext;
                    }
                    break;

                case 'user':
                    if ($item->params->get('userItemIntroText')) {
                        $item->text .= $item->introtext;
                    }
                    break;

                case 'tag':
                    if ($item->params->get('tagItemIntroText')) {
                        $item->text .= $item->introtext;
                    }
                    break;

                default:
                    if ($item->params->get('genericItemIntroText')) {
                        $item->text .= $item->introtext;
                    }
                    break;
            }
        }

        $item->event = new stdClass;
        $item->event->BeforeDisplay = '';
        $item->event->AfterDisplay = '';

        /* since J4 compatibility */
        Factory::getApplication()->triggerEvent('onContentPrepare', array(
            'com_k2.' . $view,
            &$item,
            &$params,
            $limitstart
        ));

        /* since J4 compatibility */
        $results = Factory::getApplication()->triggerEvent('onContentAfterTitle', array(
            'com_k2.' . $view,
            &$item,
            &$params,
            $limitstart
        ));
        $item->event->AfterDisplayTitle = trim(implode("\n", $results));

        /* since J4 compatibility */
        $results = Factory::getApplication()->triggerEvent('onContentBeforeDisplay', array(
            'com_k2.' . $view,
            &$item,
            &$params,
            $limitstart
        ));
        $item->event->BeforeDisplayContent = trim(implode("\n", $results));

        /* since J4 compatibility */
        $results = Factory::getApplication()->triggerEvent('onContentAfterDisplay', array(
            'com_k2.' . $view,
            &$item,
            &$params,
            $limitstart
        ));
        $item->event->AfterDisplayContent = trim(implode("\n", $results));

        // K2 plugins
        $item->event->K2BeforeDisplay = '';
        $item->event->K2AfterDisplay = '';
        $item->event->K2AfterDisplayTitle = '';
        $item->event->K2BeforeDisplayContent = '';
        $item->event->K2AfterDisplayContent = '';

        if (
            $item->params->get('itemK2Plugins') ||
            $item->params->get('catItemK2Plugins') ||
            $item->params->get('userItemK2Plugins')
            /*
            ($view == 'item' && $item->params->get('itemK2Plugins')) ||
            ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemK2Plugins')) ||
            ($view == 'itemlist' && $task == 'user' && $item->params->get('userItemK2Plugins')) ||
            ($view == 'itemlist' && ($task == 'search' || $task == 'tag' || $task == 'date'))
            */
        ) {
            /* since J4 compatibility */
            $results = Factory::getApplication()->triggerEvent('onK2BeforeDisplay', array(
                &$item,
                &$params,
                $limitstart
            ));
            $item->event->K2BeforeDisplay = trim(implode("\n", $results));

            /* since J4 compatibility */
            $results = Factory::getApplication()->triggerEvent('onK2AfterDisplay', array(
                &$item,
                &$params,
                $limitstart
            ));
            $item->event->K2AfterDisplay = trim(implode("\n", $results));

            /* since J4 compatibility */
            $results = Factory::getApplication()->triggerEvent('onK2AfterDisplayTitle', array(
                &$item,
                &$params,
                $limitstart
            ));
            $item->event->K2AfterDisplayTitle = trim(implode("\n", $results));

            /* since J4 compatibility */
            $results = Factory::getApplication()->triggerEvent('onK2BeforeDisplayContent', array(
                &$item,
                &$params,
                $limitstart
            ));
            $item->event->K2BeforeDisplayContent = trim(implode("\n", $results));

            /* since J4 compatibility */
            $results = Factory::getApplication()->triggerEvent('onK2AfterDisplayContent', array(
                &$item,
                &$params,
                $limitstart
            ));
            $item->event->K2AfterDisplayContent = trim(implode("\n", $results));

            /* since J4 compatibility */
            Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                &$item,
                &$params,
                $limitstart
            ));
        }

        if ($view == 'item') {
            @list($item->introtext, $item->fulltext) = explode('{K2Splitter}', $item->text);
        } else {
            $item->introtext = $item->text;
        }

        // Extra fields plugins
        if (($view == 'item' && $item->params->get('itemExtraFields')) || ($view == 'itemlist' && ($task == '' || $task == 'category') && $item->params->get('catItemExtraFields')) || ($view == 'itemlist' && $task == 'tag' && $item->params->get('tagItemExtraFields')) || ($view == 'itemlist' && ($task == 'search' || $task == 'date') && $item->params->get('genericItemExtraFields'))) {
            if (isset($item->extra_fields) && count($item->extra_fields)) {
                foreach ($item->extra_fields as $key => $extraField) {
                    if ($extraField->type == 'textarea' || $extraField->type == 'textfield') {
                        // Create temp object to parse plugins
                        $extraFieldTempText = new stdClass;
                        $extraFieldTempText->text = $extraField->value;
                        /* since J4 compatibility */
                        Factory::getApplication()->triggerEvent('onContentPrepare', array(
                            'com_k2.' . $view . '-extrafields',
                            &$extraFieldTempText,
                            &$params,
                            $limitstart
                        ));
                        /* since J4 compatibility */
                        Factory::getApplication()->triggerEvent('onK2PrepareContent', array(
                            &$extraFieldTempText,
                            &$params,
                            $limitstart
                        ));
                        $extraField->value = $extraFieldTempText->text;
                    }
                }
            }
        }
        return $item;
    }

    public function hit($id)
    {
        $row = Table::getInstance('K2Item', 'Table');
        $row->hit($id);
    }

    public function vote()
    {
        $app = Factory::getApplication();
        Table::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR . '/tables');

        // Get item
        $item = Table::getInstance('K2Item', 'Table');
        $item->load(Factory::getApplication()->input->getInt('itemID'));

        // Get category
        $category = Table::getInstance('K2Category', 'Table');
        $category->load($item->catid);

        // Access check
        $user = Factory::getUser();
        if (!in_array($item->access, $user->getAuthorisedViewLevels()) || !in_array($category->access, $user->getAuthorisedViewLevels())) {
            throw new \Exception(Text::_('K2_ALERTNOTAUTH'), 403);
        }

        // Published check
        if (!$item->published || $item->trash) {
            throw new \Exception(Text::_('K2_ITEM_NOT_FOUND'), 404);
        }
        if (!$category->published || $category->trash) {
            throw new \Exception(Text::_('K2_CATEGORY_NOT_FOUND'), 404);
        }

        $rate = Factory::getApplication()->input->getVar('user_rating', 0, '', 'int');

        if ($rate >= 1 && $rate <= 5) {
            $db = Factory::getDbo();
            $userIP = $_SERVER['REMOTE_ADDR'];
            $query = "SELECT * FROM #__k2_rating WHERE itemID =" . (int)$item->id;
            $db->setQuery($query);
            $rating = $db->loadObject();

            if (!$rating) {
                $query = "INSERT INTO #__k2_rating ( itemID, lastip, rating_sum, rating_count ) VALUES ( " . (int)$item->id . ", " . $db->Quote($userIP) . ", {$rate}, 1 )";
                $db->setQuery($query);
                $db->execute();
                echo Text::_('K2_THANKS_FOR_RATING');
            } else {
                if ($userIP != ($rating->lastip)) {
                    $query = "UPDATE #__k2_rating SET rating_count = rating_count + 1, rating_sum = rating_sum + {$rate}, lastip = " . $db->Quote($userIP) . " WHERE itemID = {$item->id}";
                    $db->setQuery($query);
                    $db->execute();
                    echo Text::_('K2_THANKS_FOR_RATING');
                } else {
                    echo Text::_('K2_YOU_HAVE_ALREADY_RATED_THIS_ITEM');
                }
            }
        }
        $app->close();
    }

    public function getRating($id)
    {
        $id = (int)$id;
        static $K2RatingsInstances = array();
        if (array_key_exists($id, $K2RatingsInstances)) {
            return $K2RatingsInstances[$id];
        }
        $db = Factory::getDbo();
        $query = "SELECT * FROM #__k2_rating WHERE itemID = " . $id;
        $db->setQuery($query);
        $vote = $db->loadObject();
        $K2RatingsInstances[$id] = $vote;
        return $K2RatingsInstances[$id];
    }

    public function getVotesNum($itemID = null)
    {
        $app = Factory::getApplication();
        $user = Factory::getUser();
        $xhr = false;
        if (is_null($itemID)) {
            $itemID = Factory::getApplication()->input->getInt('itemID');
            $xhr = true;
        }
        $vote = $this->getRating($itemID);
        if (!is_null($vote)) {
            $rating_count = intval($vote->rating_count);
        } else {
            $rating_count = 0;
        }
        if ($rating_count != 1) {
            $result = "(" . $rating_count . " " . Text::_('K2_VOTES') . ")";
        } else {
            $result = "(" . $rating_count . " " . Text::_('K2_VOTE') . ")";
        }
        if ($xhr) {
            echo $result;
            $app->close();
        } else {
            return $result;
        }
    }

    public function getVotesPercentage($itemID = null)
    {
        $app = Factory::getApplication();
        $user = Factory::getUser();
        $db = Factory::getDbo();
        $xhr = false;
        $result = 0;
        if (is_null($itemID)) {
            $itemID = Factory::getApplication()->input->getInt('itemID');
            $xhr = true;
        }
        $vote = $this->getRating($itemID);
        if (!is_null($vote) && $vote->rating_count != 0) {
            $result = number_format(intval($vote->rating_sum) / intval($vote->rating_count), 2) * 20;
        }
        if ($xhr) {
            echo $result;
            $app->close();
        } else {
            return $result;
        }
    }

    public function comment()
    {
        $app = Factory::getApplication();
        jimport('joomla.mail.helper');
        Table::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR . '/tables');
        $params = K2HelperUtilities::getParams('com_k2');
        $user = Factory::getUser();
        $config = Factory::getConfig();
        $response = new stdClass;

        // Get item
        $item = Table::getInstance('K2Item', 'Table');
        $item->load(Factory::getApplication()->input->getInt('itemID'));

        // Get category
        $category = Table::getInstance('K2Category', 'Table');
        $category->load($item->catid);

        // Access check
        if (!in_array($item->access, $user->getAuthorisedViewLevels()) || !in_array($category->access, $user->getAuthorisedViewLevels())) {
            throw new \Exception(Text::_('K2_ALERTNOTAUTH'), 404);
        }

        // Published check
        if (!$item->published || $item->trash) {
            throw new \Exception(Text::_('K2_ITEM_NOT_FOUND'), 404);
        }
        if (!$category->published || $category->trash) {
            throw new \Exception(Text::_('K2_CATEGORY_NOT_FOUND'), 404);
        }

        // Check permissions
        if ((($params->get('comments') == '2') && ($user->id > 0) && K2HelperPermissions::canAddComment($item->catid)) || ($params->get('comments') == '1')) {

            // If new antispam settings are not saved, show a message to the comments form and stop the comment submission
            $antispamProtection = $params->get('antispam', null);
            if (
                $antispamProtection === null ||
                (($antispamProtection == 'recaptcha' || $antispamProtection == 'both') && ((null === $params->get('recaptcha_public_key') || $params->get('recaptcha_public_key') === '') || (null === $params->get('recaptcha_private_key') || $params->get('recaptcha_private_key') == ''))) ||
                (($antispamProtection == 'akismet' || $antispamProtection == 'both') && (null === $params->get('akismetApiKey') || $params->get('akismetApiKey') ===''))
            ) {
                $response->message = Text::_('K2_ANTISPAM_SETTINGS_ERROR');
                $response->cssClass = 'k2FormLogError';
                echo json_encode($response);
                $app->close();
            }

            $row = Table::getInstance('K2Comment', 'Table');

            if (!$row->bind(Factory::getApplication()->input->getArray($_POST))) {
                $response->message = $row->getError();
                $response->cssClass = 'k2FormLogError';
                echo json_encode($response);
                $app->close();
            }

            $row->commentText = Factory::getApplication()->input->getString('commentText', '', 'default');
            $row->commentText = strip_tags($row->commentText);

            // Clean vars
            $filter = InputFilter::getInstance();
            $row->userName = $filter->clean($row->userName, 'username');
            if ($row->commentURL && preg_match('/^((http|https|ftp):\/\/)?[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,6}((:[0-9]{1,5})?\/.*)?$/i', $row->commentURL)) {
                $url = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $row->commentURL);
                $url = str_replace(';//', '://', $url);
                if ($url != '') {
                    $url = (!strstr($url, '://')) ? 'http://' . $url : $url;
                    $url = preg_replace('/&([^#])(?![a-z]{2,8};)/', '&#038;$1', $url);
                    $row->commentURL = $url;
                }
            } else {
                $row->commentURL = '';
            }

            $datenow = Factory::getDate();
            $row->commentDate = $datenow->toSql();

            if (!$user->guest) {
                $row->userID = $user->id;
                $row->commentEmail = $user->email;
                $row->userName = $user->name;
            }

            $userName = trim($row->userName);
            $commentEmail = trim($row->commentEmail);
            $commentText = trim($row->commentText);
            $commentURL = trim($row->commentURL);

            if (empty($userName) || $userName == Text::_('K2_ENTER_YOUR_NAME') || empty($commentText) || $commentText == Text::_('K2_ENTER_YOUR_MESSAGE_HERE') || empty($commentEmail) || $commentEmail == Text::_('K2_ENTER_YOUR_EMAIL_ADDRESS')) {
                $response->message = Text::_('K2_YOU_NEED_TO_FILL_IN_ALL_REQUIRED_FIELDS');
                $response->cssClass = 'k2FormLogError';
                echo json_encode($response);
                $app->close();
            }

            if (!MailHelper::isEmailAddress($commentEmail)) {
                $response->message = Text::_('K2_INVALID_EMAIL_ADDRESS');
                $response->cssClass = 'k2FormLogError';
                echo json_encode($response);
                $app->close();
            }

            if ($user->guest) {
                $db = Factory::getDbo();
                $query = "SELECT COUNT(*) FROM #__users WHERE name=" . $db->Quote($userName) . " OR email=" . $db->Quote($commentEmail);
                $db->setQuery($query);
                $result = $db->loadresult();
                if ($result > 0) {
                    $response->message = Text::_('K2_THE_NAME_OR_EMAIL_ADDRESS_YOU_TYPED_IS_ALREADY_IN_USE');
                    $response->cssClass = 'k2FormLogError';
                    echo json_encode($response);
                    $app->close();
                }
            }

            // Google reCAPTCHA
            if ($params->get('antispam') == 'recaptcha' || $params->get('antispam') == 'both') {
                if ($user->guest || $params->get('recaptchaForRegistered', 1)) {
                    require_once JPATH_SITE . '/components/com_k2/helpers/utilities.php';
                    if (!K2HelperUtilities::verifyRecaptcha()) {
                        $response->message = Text::_('K2_COULD_NOT_VERIFY_THAT_YOU_ARE_NOT_A_ROBOT');
                        $response->cssClass = 'k2FormLogError';
                        echo json_encode($response);
                        $app->close();
                    }
                }
            }

            // Akismet
            if ($params->get('antispam') == 'akismet' || $params->get('antispam') == 'both') {
                if ($user->guest || $params->get('akismetForRegistered', 1)) {
                    if ($params->get('akismetApiKey')) {
                        require_once(JPATH_SITE . '/media/k2/assets/vendors/achingbrain/php5-akismet/akismet.class.php');
                        $akismetApiKey = trim($params->get('akismetApiKey'));
                        $akismet = new Akismet(JURI::root(false), $akismetApiKey);
                        $akismet->setCommentAuthor($userName);
                        $akismet->setCommentAuthorEmail($commentEmail);
                        $akismet->setCommentAuthorURL($commentURL);
                        $akismet->setCommentContent($commentText);
                        $akismet->setPermalink(JURI::root(false) . 'index.php?option=com_k2&view=item&id=' . Factory::getApplication()->input->getInt('itemID'));
                        try {
                            if ($akismet->isCommentSpam()) {
                                $response->message = Text::_('K2_SPAM_ATTEMPT_HAS_BEEN_DETECTED_THE_COMMENT_HAS_BEEN_REJECTED');
                                $response->cssClass = 'k2FormLogError';
                                echo json_encode($response);
                                $app->close();
                            }
                        } catch (Exception $e) {
                            $response->message = $e->getMessage();
                            $response->cssClass = 'k2FormLogSuccess';
                            echo json_encode($response);
                            $app->close();
                        }
                    }
                }
            }

            if ($commentURL == Text::_('K2_ENTER_YOUR_SITE_URL') || $commentURL == "") {
                $row->commentURL = '';
            } else {
                if (substr(trim($commentURL), 0, 4) != 'http') {
                    $row->commentURL = 'http://' . $commentURL;
                }
            }

            if ($params->get('commentsPublishing', false)) {
                $row->published = 1;
            } else {
                $row->published = 0;
                // Auto publish comments for users with administrative permissions
                if ($user->authorise('core.admin')) {
                    $row->published = 1;
                }
            }

            if (!$row->store()) {
                $response->message = $row->getError();
                $response->cssClass = 'k2FormLogError';
                echo json_encode($response);
                $app->close();
            }

            if ($row->published) {
                $caching = $config->get('caching');
                if ($caching && $user->guest) {
                    $response->message = Text::_('K2_THANK_YOU_YOUR_COMMENT_WILL_BE_PUBLISHED_SHORTLY');
                    $response->cssClass = 'k2FormLogSuccess';
                    echo json_encode($response);
                } else {
                    $response->message = Text::_('K2_COMMENT_ADDED_REFRESHING_PAGE');
                    $response->cssClass = 'k2FormLogSuccess';
                    $response->refresh = 1;
                    echo json_encode($response);
                }
            } else {
                $response->message = Text::_('K2_COMMENT_ADDED_AND_WAITING_FOR_APPROVAL');
                $response->cssClass = 'k2FormLogSuccess';
                echo json_encode($response);
            }
        }
        $app->close();
    }

    public function getItemTags($itemID)
    {
        $itemID = (int)$itemID;
        static $K2ItemTagsInstances = array();
        if (isset($K2ItemTagsInstances[$itemID])) {
            return $K2ItemTagsInstances[$itemID];
        }
        $db = Factory::getDbo();
        $query = "SELECT tag.*
            FROM #__k2_tags AS tag
            JOIN #__k2_tags_xref AS xref ON tag.id = xref.tagID
            WHERE tag.published = 1
                AND xref.itemID = " . (int)$itemID . "
            ORDER BY xref.id ASC";

        $db->setQuery($query);
        $rows = $db->loadObjectList();
        $K2ItemTagsInstances[$itemID] = $rows;
        return $K2ItemTagsInstances[$itemID];
    }

    public function getItemExtraFields($itemExtraFields, &$item = null)
    {
        static $K2ItemExtraFieldsInstances = array();
        if ($item && isset($K2ItemExtraFieldsInstances[$item->id])) {
            $this->buildAliasBasedExtraFields($K2ItemExtraFieldsInstances[$item->id], $item);
            return $K2ItemExtraFieldsInstances[$item->id];
        }

        jimport('joomla.filesystem.file');
        $db = Factory::getDbo();
        $jsonObjects = json_decode($itemExtraFields);
        $imgExtensions = array(
            'jpg',
            'jpeg',
            'gif',
            'png'
        );
        $params = K2HelperUtilities::getParams('com_k2');

        if ($jsonObjects == null || count($jsonObjects) < 1) {
            return null;
        }

        foreach ($jsonObjects as $object) {
            $extraFieldsIDs[] = $object->id;
        }
        ArrayHelper::toInteger($extraFieldsIDs);
        $condition = @implode(',', $extraFieldsIDs);

        $query = "SELECT extraFieldsGroup FROM #__k2_categories WHERE id=" . (int)$item->catid;
        $db->setQuery($query);
        $group = $db->loadResult();

        $query = "SELECT * FROM #__k2_extra_fields WHERE `group` = " . (int)$group . " AND published=1 AND (id IN ({$condition}) OR `type` = 'header') ORDER BY ordering ASC";
        $db->setQuery($query);
        $rows = $db->loadObjectList();
        $size = count($rows);

        for ($i = 0; $i < $size; $i++) {
            $value = '';
            $rawValue = '';
            $values = array();
            foreach ($jsonObjects as $object) {
                if ($rows[$i]->id == $object->id) {
                    if ($rows[$i]->type == 'textfield' || $rows[$i]->type == 'textarea' || $rows[$i]->type == 'date') {
                        $value = $object->value;
                        if ($rows[$i]->type == 'date' && $value) {
                            $rawValue = $value;
                            $offset = null;
                            $value = JHTML::_('date', $value, Text::_('K2_DATE_FORMAT_LC'), $offset);
                        }
                    } elseif ($rows[$i]->type == 'image') {
                        if ($object->value) {
                            $src = '';
                            if (strpos($object->value, '://') === false) {
                                $src .= JURI::root(true) . '/' . $object->value;
                                $src = str_replace('//', '/', $src); // Merge duplicate forward slashes
                            } else {
                                $src .= $object->value;
                            }
                            $src = str_replace('\\', '/', $src); // Normalize paths on Windows
                            $value = '<img src="' . $src . '" alt="' . $rows[$i]->name . '" />';
                        } else {
                            $value = false;
                        }
                    } elseif ($rows[$i]->type == 'labels') {
                        $labels = explode(',', $object->value);
                        if (!is_array($labels)) {
                            $labels = (array)$labels;
                        }
                        $value = '';
                        foreach ($labels as $label) {
                            $label = trim($label);
                            if ($label != '') {
                                $label = str_replace('-', ' ', $label);
                                $value .= '<a href="' . Route::_('index.php?option=com_k2&view=itemlist&task=search&searchword=' . urlencode($label)) . '">' . $label . '</a>';
                            }
                        }
                    } elseif ($rows[$i]->type == 'select' || $rows[$i]->type == 'radio') {
                        foreach (json_decode($rows[$i]->value) as $option) {
                            if ($option->value == $object->value) {
                                $value .= $option->name;
                            }
                        }
                    } elseif ($rows[$i]->type == 'multipleSelect') {
                        foreach (json_decode($rows[$i]->value) as $option) {
                            if (@in_array($option->value, $object->value)) {
                                $values[] = $option->name;
                            }
                        }
                        $value = @implode(', ', $values);
                    } elseif ($rows[$i]->type == 'csv') {
                        $array = $object->value;
                        if (isset($array) && count($array)) {
                            $value .= '<table cellspacing="0" cellpadding="0" class="csvTable">';
                            foreach ($array as $key => $row) {
                                $value .= '<tr>';
                                foreach ($row as $cell) {
                                    $value .= ($key > 0) ? '<td>' . $cell . '</td>' : '<th>' . $cell . '</th>';
                                }
                                $value .= '</tr>';
                            }
                            $value .= '</table>';
                        }
                    } else {
                        switch ($object->value[2]) {
                            case 'same':
                            default:
                                $attributes = '';
                                break;

                            case 'new':
                                $attributes = 'target="_blank"';
                                break;

                            case 'popup':
                                $attributes = 'class="classicPopup" rel="{\'x\':' . $params->get('linkPopupWidth') . ',\'y\':' . $params->get('linkPopupHeight') . '}"';
                                break;

                            case 'lightbox':

                                // Joomla modal required
                                if (!defined('K2_JOOMLA_MODAL_REQUIRED')) {
                                    define('K2_JOOMLA_MODAL_REQUIRED', true);
                                }

                                $filename = @basename($object->value[1]);
                                $extension = File::getExt($filename);
                                if (!empty($extension) && in_array($extension, $imgExtensions)) {
                                    $attributes = 'data-k2-modal="image"';
                                } else {
                                    $attributes = 'data-k2-modal="iframe"';
                                }
                                break;
                        }
                        $object->value[0] = trim($object->value[0]);
                        $object->value[1] = trim($object->value[1]);

                        if ($object->value[1] && $object->value[1] != 'http://' && $object->value[1] != 'https://') {
                            if ($object->value[0] == '') {
                                $object->value[0] = $object->value[1];
                            }
                            $rows[$i]->url = $object->value[1];
                            $rows[$i]->text = $object->value[0];
                            $rows[$i]->attributes = $attributes;
                            $value = '<a href="' . $object->value[1] . '" ' . $attributes . '>' . $object->value[0] . '</a>';
                            $rawValue = $object->value[1];
                        } else {
                            $value = false;
                        }
                    }
                }
            }

            if ($rows[$i]->type == 'header') {
                $tmp = json_decode($rows[$i]->value);
                if (!$tmp[0]->displayInFrontEnd) {
                    $value = null;
                } else {
                    $value = $tmp[0]->value;
                }
            }

            // Detect alias
            $tmpValues = json_decode($rows[$i]->value);
            if (isset($tmpValues[0]) && isset($tmpValues[0]->alias) && !empty($tmpValues[0]->alias)) {
                $rows[$i]->alias = $tmpValues[0]->alias;
            } else {
                $filter = InputFilter::getInstance();
                $rows[$i]->alias = $filter->clean($rows[$i]->name, 'WORD');
                if (!$rows[$i]->alias) {
                    $rows[$i]->alias = 'extraField' . $rows[$i]->id;
                }
            }

            if (trim($value) != '') {
                if (trim($rawValue) != '') {
                    $rows[$i]->rawValue = $rawValue;
                }
                $rows[$i]->value = $value;
                if (!is_null($item)) {
                    if (!isset($item->extraFields)) {
                        $item->extraFields = new stdClass;
                    }
                    $tmpAlias = $rows[$i]->alias;
                    $item->extraFields->$tmpAlias = $rows[$i];
                }
            } else {
                unset($rows[$i]);
            }
        }

        if ($item) {
            $K2ItemExtraFieldsInstances[$item->id] = $rows;
        }
        $this->buildAliasBasedExtraFields($K2ItemExtraFieldsInstances[$item->id], $item);
        return $K2ItemExtraFieldsInstances[$item->id];
    }

    public function buildAliasBasedExtraFields($extraFields, &$item)
    {
        if (is_null($item)) {
            return false;
        }
        if (!isset($item->extraFields)) {
            $item->extraFields = new stdClass;
        }
        foreach ($extraFields as $extraField) {
            $tmpAlias = $extraField->alias;
            $item->extraFields->$tmpAlias = $extraField;
        }
    }

    public function getItemAttachments($itemID)
    {
        $itemID = (int)$itemID;
        static $K2ItemAttachmentsInstances = array();
        if (isset($K2ItemAttachmentsInstances[$itemID])) {
            return $K2ItemAttachmentsInstances[$itemID];
        }
        $db = Factory::getDbo();
        $query = "SELECT * FROM #__k2_attachments WHERE itemID=" . $itemID;
        $db->setQuery($query);
        $rows = $db->loadObjectList();
        foreach ($rows as $row) {
            $hash = JApplicationHelper::getHash($row->id);
            $row->link = Route::_('index.php?option=com_k2&view=item&task=download&id=' . $row->id . '_' . $hash);
        }
        $K2ItemAttachmentsInstances[$itemID] = $rows;
        return $K2ItemAttachmentsInstances[$itemID];
    }

    public function getItemComments($itemID, $limitstart, $limit, $published = true)
    {
        $params = K2HelperUtilities::getParams('com_k2');
        $order = $params->get('commentsOrdering', 'DESC');
        $ordering = ($order == 'DESC') ? 'DESC' : 'ASC';
        $db = Factory::getDbo();
        $query = "SELECT * FROM #__k2_comments WHERE itemID=" . (int)$itemID;
        if ($published) {
            $query .= " AND published=1 ";
        }
        $query .= " ORDER BY commentDate {$ordering}";
        $db->setQuery($query, $limitstart, $limit);
        $rows = $db->loadObjectList();
        return $rows;
    }

    public function countItemComments($itemID, $published = true)
    {
        $itemID = (int)$itemID;
        $index = $itemID . '_' . (int)$published;
        static $K2ItemCommentsCountInstances = array();
        if (isset($K2ItemCommentsCountInstances[$index])) {
            return $K2ItemCommentsCountInstances[$index];
        }
        $db = Factory::getDbo();
        $query = "SELECT COUNT(*) FROM #__k2_comments WHERE itemID=" . $itemID;
        if ($published) {
            $query .= " AND published=1 ";
        }
        $db->setQuery($query);
        $result = $db->loadResult();
        $K2ItemCommentsCountInstances[$index] = $result;
        return $K2ItemCommentsCountInstances[$index];
    }

    public function checkin()
    {
        $app = Factory::getApplication();
        $id = Factory::getApplication()->input->getInt('cid');
        if ($id) {
            $row = Table::getInstance('K2Item', 'Table');
            $row->load($id);
            $row->checkin();
        } else {
            // Clean up SIGPro
            $sigProFolder = Factory::getApplication()->input->getCmd('sigProFolder');
            if ($sigProFolder && !is_numeric($sigProFolder) && Folder::exists(JPATH_SITE . '/media/k2/galleries/' . $sigProFolder)) {
                Folder::delete(JPATH_SITE . '/media/k2/galleries/' . $sigProFolder);
            }
        }
        $app->close();
    }

    public function getAdjacentItem($id, $catid, $ordering, $direction)
    {
        $app = Factory::getApplication();
        $user = Factory::getUser();

        $id = (int)$id;
        $catid = (int)$catid;
        $ordering = (int)$ordering;

        $db = Factory::getDbo();
        $jnow = Factory::getDate();
        $now = $jnow->toSql();
        $nullDate = $db->getNullDate();

        $accessCondition = 'AND access IN (' . implode(',', $user->getAuthorisedViewLevels()) . ')';

        $languageCondition = '';
        if ($app->getLanguageFilter()) {
            $languageCondition = "AND language IN (" . $db->quote(JFactory::getLanguage()->getTag()) . ", " . $db->quote('*') . ")";
        }

        if ($direction == 'prev') {
            $dirOperand = '<';
            $dirSorting = 'DESC';
        } else {
            $dirOperand = '>';
            $dirSorting = 'ASC';
        }

        if ($ordering == "0") {
            $orderCondition = "AND id {$dirOperand} {$id}";
        } else {
            $orderCondition = "AND id != {$id} AND ordering {$dirOperand} {$ordering}";
        }

        $query = "SELECT *
            FROM #__k2_items
            WHERE catid = {$catid}
                AND published = 1
                AND trash = 0
                {$orderCondition}
                AND (publish_up = " . $db->Quote($nullDate) . " OR publish_up <= " . $db->Quote($now) . ")
                AND (publish_down = " . $db->Quote($nullDate) . " OR publish_down >= " . $db->Quote($now) . ")
                {$accessCondition}
                {$languageCondition}
            ORDER BY ordering {$dirSorting}";

        $db->setQuery($query, 0, 1);
        $row = $db->loadObject();
        return $row;
    }

    public function getPreviousItem($id, $catid, $ordering, $catOrdering)
    {
        $app = Factory::getApplication();
        $user = Factory::getUser();

        $id = (int)$id;
        $catid = (int)$catid;
        $ordering = (int)$ordering;

        $db = Factory::getDbo();
        $jnow = Factory::getDate();
        $now = $jnow->toSql();
        $nullDate = $db->getNullDate();

        $accessCondition = 'AND access IN (' . implode(',', $user->getAuthorisedViewLevels()) . ')';

        $languageCondition = '';
        if ($app->getLanguageFilter()) {
            $languageCondition = "AND language IN (" . $db->quote(JFactory::getLanguage()->getTag()) . ", " . $db->quote('*') . ")";
        }

        $query = "SELECT *
            FROM #__k2_items
            WHERE id < {$id}
                AND catid = {$catid}
                AND published = 1
                AND trash = 0
                AND (publish_up = " . $db->Quote($nullDate) . " OR publish_up <= " . $db->Quote($now) . ")
                AND (publish_down = " . $db->Quote($nullDate) . " OR publish_down >= " . $db->Quote($now) . ")
                {$accessCondition}
                {$languageCondition}
            ORDER BY id DESC";

        if ($catOrdering == 'order') {
            $query = "SELECT *
                FROM #__k2_items
                WHERE id != {$id}
                    AND catid = {$catid}
                    AND ordering < {$ordering}
                    AND published = 1
                    AND trash = 0
                    AND (publish_up = " . $db->Quote($nullDate) . " OR publish_up <= " . $db->Quote($now) . ")
                    AND (publish_down = " . $db->Quote($nullDate) . " OR publish_down >= " . $db->Quote($now) . ")
                    {$accessCondition}
                    {$languageCondition}
                ORDER BY ordering DESC";
        }

        $db->setQuery($query, 0, 1);
        $row = $db->loadObject();
        return $row;
    }

    public function getNextItem($id, $catid, $ordering, $catOrdering)
    {
        $app = Factory::getApplication();
        $user = Factory::getUser();

        $id = (int)$id;
        $catid = (int)$catid;
        $ordering = (int)$ordering;

        $db = Factory::getDbo();
        $jnow = Factory::getDate();
        $now = $jnow->toSql();
        $nullDate = $db->getNullDate();

        $accessCondition = 'AND access IN (' . implode(',', $user->getAuthorisedViewLevels()) . ')';

        $languageCondition = '';
        if ($app->getLanguageFilter()) {
            $languageCondition = "AND language IN (" . $db->quote(JFactory::getLanguage()->getTag()) . ", " . $db->quote('*') . ")";
        }

        $query = "SELECT *
            FROM #__k2_items
            WHERE id > {$id}
                AND catid = {$catid}
                AND published = 1
                AND trash = 0
                AND (publish_up = " . $db->Quote($nullDate) . " OR publish_up <= " . $db->Quote($now) . ")
                AND (publish_down = " . $db->Quote($nullDate) . " OR publish_down >= " . $db->Quote($now) . ")
                {$accessCondition}
                {$languageCondition}
            ORDER BY id ASC";

        if ($catOrdering == 'order') {
            $query = "SELECT *
                FROM #__k2_items
                WHERE id != {$id}
                    AND catid = {$catid}
                    AND ordering > {$ordering}
                    AND published = 1
                    AND trash = 0
                    AND (publish_up = " . $db->Quote($nullDate) . " OR publish_up <= " . $db->Quote($now) . ")
                    AND (publish_down = " . $db->Quote($nullDate) . " OR publish_down >= " . $db->Quote($now) . ")
                    {$accessCondition}
                    {$languageCondition}
                ORDER BY ordering ASC";
        }

        $db->setQuery($query, 0, 1);
        $row = $db->loadObject();
        return $row;
    }

    public function getUserProfile($id = null)
    {
        $db = Factory::getDbo();
        if (is_null($id)) {
            $id = Factory::getApplication()->input->getInt('id', 0);
        }
		if(!$id){
			return;
		}

        static $K2UsersInstances = array();
        if (isset($K2UsersInstances[$id])) {
            return $K2UsersInstances[$id];
        }

        $query = "SELECT id, gender, description, image, url, `group`, plugins FROM #__k2_users WHERE userID={$id}";
        $db->setQuery($query);
        $row = $db->loadObject();
        $K2UsersInstances[$id] = $row;
        return $row;
    }
}
