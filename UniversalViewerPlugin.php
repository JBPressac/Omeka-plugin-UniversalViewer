<?php
/**
 * Universal Viewer
 *
 * This plugin integrates the Universal Viewer, the open sourced viewer taht is
 * the successor of the Wellcome Viewer of Digirati, into Omeka.
 *
 * @copyright Daniel Berthereau, 2015
 * @license https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
 * @license https://github.com/UniversalViewer/universalviewer/blob/master/LICENSE.txt (viewer)
 *  */

/**
 * The Universal Viewer plugin.
 * @package Omeka\Plugins\UniversalViewer
 */
class UniversalViewerPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'install',
        'upgrade',
        'uninstall',
        'initialize',
        'config_form',
        'config',
        'define_routes',
        'admin_items_batch_edit_form',
        'items_batch_edit_custom',
        'public_collections_show',
        'public_items_show',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array(
        // It's a checkbox, so no error can be done.
        // 'items_batch_edit_error',
    );

    /**
     * @var array Options and their default values.
     */
    protected $_options = array(
        'universalviewer_manifest_description_element' => '',
        'universalviewer_manifest_description_default' => true,
        'universalviewer_manifest_attribution_element' => '',
        'universalviewer_manifest_attribution_default' => 'Provided by Example Organization',
        'universalviewer_manifest_license_element' => '["Dublin Core", "Rights"]',
        'universalviewer_manifest_license_default' => 'http://www.example.org/license.html',
        'universalviewer_alternative_manifest_element' => '',
        'universalviewer_append_collections_show' => true,
        'universalviewer_append_items_show' => true,
        'universalviewer_class' => '',
        'universalviewer_width' => '95%',
        'universalviewer_height' => '600px',
        'universalviewer_locale' => 'en-GB:English (GB),fr-FR:French',
        'universalviewer_iiif_creator' => 'Auto',
        'universalviewer_max_dynamic_size' => 10000000,
        'universalviewer_force_https' => false,
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        $processors = $this->_getProcessors();
        if (count($processors) == 1) {
            throw new Omeka_Plugin_Installer_Exception(__('At least one graphic processor (GD or ImageMagick) is required to use the UniversalViewer.'));
        }

        $js = dirname(__FILE__)
            . DIRECTORY_SEPARATOR . 'views'
            . DIRECTORY_SEPARATOR . 'shared'
            . DIRECTORY_SEPARATOR . 'javascripts'
            . DIRECTORY_SEPARATOR . 'uv'
            . DIRECTORY_SEPARATOR . 'lib'
            . DIRECTORY_SEPARATOR . 'embed.js';
        if (!file_exists($js)) {
            throw new Omeka_Plugin_Installer_Exception(__('UniversalViewer library should be installed. See %sReadme%s.',
                '<a href="https://github.com/Daniel-KM/UniversalViewer4Omeka#installation">', '</a>'));
        }

        if (plugin_is_active('DublinCoreExtended')) {
            $this->_options['universalviewer_manifest_description_element'] = json_encode(array('Dublin Core', 'Bibliographic Citation'));
            $this->_options['universalviewer_manifest_license_element'] = json_encode(array('Dublin Core', 'License'));
        }

        $this->_installOptions();
    }

    /**
     * Upgrade the plugin.
     */
    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];
        $newVersion = $args['new_version'];
        $db = $this->_db;

        if (version_compare($oldVersion, '2.4', '<')) {
            if (plugin_is_active('DublinCoreExtended')) {
                $element = json_encode(array('Dublin Core', 'Bibliographic Citation'));
            } else {
                $element = '';
            }
            set_option('universalviewer_manifest_description_element', $element);
            set_option('universalviewer_manifest_description_default', $this->_options['universalviewer_manifest_description_default']);

            set_option('universalviewer_manifest_attribution_element', $this->_options['universalviewer_manifest_attribution_element']);

            $value = get_option('universalviewer_attribution');
            set_option('universalviewer_manifest_attribution_default', $value);
            delete_option('universalviewer_attribution');

            if (plugin_is_active('DublinCoreExtended')) {
                $element = json_encode(array('Dublin Core', 'License'));
            } else {
                $element = json_encode(array('Dublin Core', 'Rights'));
            }
            set_option('universalviewer_manifest_license_element', $element);

            $value = get_option('universalviewer_licence');
            set_option('universalviewer_manifest_license_default', $value);
            delete_option('universalviewer_licence');

            $elementSetName = get_option('universalviewer_manifest_elementset');
            $elementName = get_option('universalviewer_manifest_element');
            $element = !empty($elementSetName) && !empty($elementName)
                ? json_encode(array($elementSetName, $elementName))
                : '';
            set_option('universalviewer_alternative_manifest_element', $element);
            delete_option('universalviewer_manifest_elementset');
            delete_option('universalviewer_manifest_element');
        }
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    /**
     * Initialize the plugin.
     */
    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');
        add_shortcode('uv', array($this, 'shortcodeUniversalViewer'));
    }

    /**
     * Shows plugin configuration page.
     *
     * @return void
     */
    public function hookConfigForm($args)
    {
        $view = get_view();

        $processors = $this->_getProcessors();

        $flash = Zend_Controller_Action_HelperBroker::getStaticHelper('FlashMessenger');
        if (count($processors) == 1) {
            $flash->addMessage(__("Warning: No graphic library is installed: Universaliewer can't work.",
                '<strong>', '</strong>'), 'error');
            echo flash();
        }

        if (!isset($processors['Imagick'])) {
            $flash->addMessage(__('Warning: Imagick is not installed: Only standard images (jpg, png, gif and webp) will be processed.',
                '<strong>', '</strong>'), 'info');
            echo flash();
        }

        $elementTable = $this->_db->getTable('Element');
        $elementIds = array();
        foreach (array(
                'universalviewer_manifest_description_element' => 'description',
                'universalviewer_manifest_attribution_element' => 'attribution',
                'universalviewer_manifest_license_element' => 'license',
                'universalviewer_alternative_manifest_element' => 'manifest',
            ) as $option => $name) {
            $element = get_option($option);
            if ($element) {
                $element = json_decode($element, true);
                $element = $elementTable
                    ->findByElementSetNameAndElementName($element[0], $element[1]);
                if ($element) {
                    $element = $element->id;
                }
            }
            $elementIds[$name] = $element;
        }

        echo $view->partial(
            'plugins/universal-viewer-config-form.php',
            array(
                'processors' => $processors,
                'element_ids' => $elementIds,
            )
        );
    }

    /**
     * Processes the configuration form.
     *
     * @param array Options set in the config form.
     * @return void
     */
    public function hookConfig($args)
    {
        $post = $args['post'];

        // Get the element set names and the element names from the element ids.
        foreach (array(
                'universalviewer_manifest_description_element',
                'universalviewer_manifest_attribution_element',
                'universalviewer_manifest_license_element',
                'universalviewer_alternative_manifest_element',
            ) as $option) {
            $elementId = $post[$option];
            if (!empty($elementId)) {
                $element = get_record_by_id('Element', $elementId);
                $post[$option] = json_encode(array(
                    $element->getElementSet()->name,
                    $element->name,
                ));
            }
        }

        foreach ($this->_options as $optionKey => $optionValue) {
            if (isset($post[$optionKey])) {
                set_option($optionKey, $post[$optionKey]);
            }
        }
    }

    /**
     * Defines public routes.
     *
     * @return void
     */
    public function hookDefineRoutes($args)
    {
        if (is_admin_theme()) {
            return;
        }

        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . '/routes.ini', 'routes'));
    }

    /**
     * Add a partial batch edit form.
     *
     * @return void
     */
    public function hookAdminItemsBatchEditForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'forms/universal-viewer-batch-edit.php'
        );
    }

    /**
     * Process the partial batch edit form.
     *
     * @return void
     */
    public function hookItemsBatchEditCustom($args)
    {
        $item = $args['item'];
        $orderByFilename = $args['custom']['universalviewer']['orderByFilename'];
        $mixImages = $args['custom']['universalviewer']['mixImages'];
        $checkImageSize = $args['custom']['universalviewer']['checkImageSize'];

        if ($orderByFilename) {
            $this->_sortFiles($item, (boolean) $mixImages);
        }

        if ($checkImageSize) {
            $this->_checkImageSize($item);
        }
    }

    /**
     * Sort all files of an item by name and eventually sort images first.
     *
     * @param Item $item
     * @param boolean $mixImages
     * @return void
     */
    protected function _sortFiles($item, $mixImages = false)
    {
        if ($item->fileCount() < 2) {
            return;
        }

        $list = $item->Files;
        // Make a sort by name before sort by type.
        usort($list, function($fileA, $fileB) {
            return strcmp($fileA->original_filename, $fileB->original_filename);
        });
        // The sort by type doesn't remix all filenames.
        if (!$mixImages) {
            $images = array();
            $nonImages = array();
            foreach ($list as $file) {
                // Image.
                if (strpos($file->mime_type, 'image/') === 0) {
                    $images[] = $file;
                }
                // Non image.
                else {
                    $nonImages[] = $file;
                }
            }
            $list = array_merge($images, $nonImages);
        }

        // To avoid issues with unique index when updating (order should be
        // unique for each file of an item), all orders are reset to null before
        // true process.
        $db = $this->_db;
        $bind = array(
            $item->id,
        );
        $sql = "
            UPDATE `$db->File` files
            SET files.order = NULL
            WHERE files.item_id = ?
        ";
        $db->query($sql, $bind);

        // To avoid multiple updates, a single query is used.
        foreach ($list as &$file) {
            $file = $file->id;
        }
        // The array is made unique, because a file can be repeated.
        $list = implode(',', array_unique($list));
        $sql = "
            UPDATE `$db->File` files
            SET files.order = FIND_IN_SET(files.id, '$list')
            WHERE files.id in ($list)
        ";
        $db->query($sql);
    }

   /**
     * Rebuild missing metadata of files.
     *
     * @param Item $item
     * @return void
     */
    protected function _checkImageSize($item)
    {
        foreach ($item->Files as $file) {
            if (!$file->hasThumbnail() || strpos($file->mime_type, 'image/') !== 0) {
                continue;
            }
            $metadata = json_decode($file->metadata, true);
            if (empty($metadata)) {
                $metadata = array();
            }
            // Check if resolution is set.
            elseif (!empty($metadata['video']['resolution_x']) && !empty($metadata['video']['resolution_y'])) {
                continue;
            }

            // Set the resolution directly.
            $imageType = 'original';
            // The storage adapter should be checked for external storage.
            $storageAdapter = $file->getStorage()->getAdapter();
            $filepath = get_class($storageAdapter) == 'Omeka_Storage_Adapter_Filesystem'
                ? FILES_DIR . DIRECTORY_SEPARATOR . $file->getStoragePath($imageType)
                : $file->getWebPath($imageType);
            list($width, $height, $type, $attr) = getimagesize($filepath);
            $metadata['video']['resolution_x'] = $width;
            $metadata['video']['resolution_y'] = $height;
            $file->metadata = version_compare(phpversion(), '5.4.0', '<')
                ? json_encode($metadata)
                : json_encode($metadata, JSON_UNESCAPED_SLASHES);
            $file->save();
        }
    }

    /**
     * Hook to display viewer.
     *
     * @param array $args
     *
     * @return void
     */
    public function hookPublicCollectionsShow($args)
    {
        if (!get_option('universalviewer_append_collections_show')) {
            return;
        }
        if (!isset($args['view'])) {
            $args['view'] = get_view();
        }
        echo $args['view']->universalViewer($args);
    }

    /**
     * Hook to display viewer.
     *
     * @param array $args
     *
     * @return void
     */
    public function hookPublicItemsShow($args)
    {
        if (!get_option('universalviewer_append_items_show')) {
            return;
        }
        if (!isset($args['view'])) {
            $args['view'] = get_view();
        }
        echo $args['view']->universalViewer($args);
    }

    /**
     * Shortcode to display viewer.
     *
     * @param array $args
     * @param Omeka_View $view
     * @return string
     */
    public static function shortcodeUniversalViewer($args, $view)
    {
        $args['view'] = $view;
        return $view->universalViewer($args);
    }

    /**
     * Check and return the list of available processors.
     *
     * @return array Associative array of available processors.
     */
    protected function _getProcessors()
    {
        $processors = array(
            'Auto' => __('Automatic'),
        );
        if (extension_loaded('gd')) {
            $processors['GD'] = 'GD';
        }
        if (extension_loaded('imagick')) {
            $processors['Imagick'] = 'ImageMagick';
        }

        return $processors;
    }
}
