<?php

namespace VisualComposer\Modules\Editors\DataAjax;

if (!defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use VisualComposer\Helpers\Access\UserCapabilities;
use VisualComposer\Helpers\Filters;
use VisualComposer\Framework\Illuminate\Support\Module;
use VisualComposer\Helpers\Request;
use VisualComposer\Helpers\Options;
use VisualComposer\Framework\Container;
use VisualComposer\Helpers\Traits\EventsFilters;
use VisualComposer\Helpers\Traits\WpFiltersActions;

/**
 * Class Controller.
 */
class Controller extends Container implements Module
{
    use EventsFilters;
    use WpFiltersActions;

    /**
     * @var \VisualComposer\Helpers\Options
     */
    protected $options;

    public function __construct(Options $optionsHelper)
    {
        $this->options = $optionsHelper;
        /** @see \VisualComposer\Modules\Editors\DataAjax\Controller::getData */
        $this->addFilter(
            'vcv:ajax:getData:adminNonce',
            'getData'
        );

        /** @see \VisualComposer\Modules\Editors\DataAjax\Controller::setData */
        $this->addFilter(
            'vcv:ajax:setData:adminNonce',
            'setData'
        );
    }

    /**
     * @param $sourceId
     * @param \WP_Post $post
     *
     * @return mixed|string
     */
    protected function getContentData($sourceId, $post)
    {
        // @codingStandardsIgnoreLine
        if ($post->post_type === 'vcv_templates') {
            // remove pageContent (moved from migration FixPredefinedTemplateUpdate)
            $type = get_post_meta($post->ID, '_' . VCV_PREFIX . 'type', true);
            if ($type === 'hub') {
                delete_post_meta($post->ID, VCV_PREFIX . 'pageContent');
            }
        }

        $data = '';
        $postMeta = get_post_meta($sourceId, VCV_PREFIX . 'pageContent', true);
        if (!empty($postMeta)) {
            $data = $postMeta;
        } else {
            // BC for hub templates and old templates
            // @codingStandardsIgnoreLine
            if ($post->post_type === 'vcv_templates' || $post->post_type === 'vcv_tutorials') {
                $editorTemplatesHelper = vchelper('EditorTemplates');
                $data = rawurlencode(
                    wp_json_encode(
                        [
                            'elements' => $editorTemplatesHelper->getTemplateElementsByMeta($sourceId),
                        ]
                    )
                );
            }
        }

        return $data;
    }

    /**
     * @param $sourceId
     *
     * @return array
     */
    protected function checkSourceId($sourceId)
    {
        $accessCheck = true;
        if (is_numeric($sourceId)) {
            return [$accessCheck, $sourceId];
        }

        if (is_array($sourceId) && $sourceId['status'] === true) {
            if (isset($sourceId['accessCheck'])) {
                $accessCheck = $sourceId['accessCheck'];
            }
            $sourceId = $sourceId['sourceId'];
        }

        return [$accessCheck, $sourceId];
    }

    /**
     * Get post content.
     *
     * @param $response
     * @param \VisualComposer\Helpers\Filters $filterHelper
     *
     * @return mixed|string
     */
    private function getData(
        $response,
        $payload,
        Filters $filterHelper
    ) {
        global $post;
        if (empty($post)) {
            return ['status' => false];
        }
        $sourceId = $post->ID;
        if (!is_array($response)) {
            $response = [];
        }
        $data = '';
        if ($post) {
            $data = $this->getContentData($sourceId, $post);
            // @codingStandardsIgnoreLine
            $response['post_content'] = $post->post_content;
            $responseExtra = $filterHelper->fire(
                'vcv:dataAjax:getData',
                [
                    'status' => true,
                ],
                $payload
            );
            $response = array_merge($response, $responseExtra);
        }
        $response['data'] = $data;

        $elementsCssData = get_post_meta($sourceId, VCV_PREFIX . 'globalElementsCssData', true);
        $response['elementsCssData'] = $elementsCssData;

        return $response;
    }

    /**
     * Save post content and used assets.
     *
     * @param $response
     * @param $payload
     * @param \VisualComposer\Helpers\Request $requestHelper
     * @param \VisualComposer\Helpers\Access\UserCapabilities $userCapabilitiesHelper
     *
     * @return array|null
     */
    private function setData(
        $response,
        $payload,
        Request $requestHelper,
        UserCapabilities $userCapabilitiesHelper
    ) {
        if (!isset($payload['sourceId'])) {
            return ['status' => false]; // sourceId must be provided
        }

        if ($requestHelper->input('vcv-ready') !== '1') {
            return $response;
        }

        $sourceId = vcfilter('vcv:dataAjax:setData:sourceId', $payload['sourceId']);
        list($accessCheck, $sourceId) = $this->checkSourceId($sourceId);

        if (!is_array($response)) {
            $response = [];
        }

        $hasAccess = $accessCheck ? $userCapabilitiesHelper->canEdit($sourceId) : true;
        if (is_numeric($sourceId) && $hasAccess) {
            $sourceId = (int)$sourceId;
            $post = get_post($sourceId);
            if ($post) {
                $this->fireRemoveUpdatePost($sourceId, $payload);

                return $this->updatePostData($post, $sourceId, $response);
            }
        }

        return ['status' => false];
    }

    /**
     * Fire event to remove post update.
     *
     * @param int $sourceId
     * @param array $payload
     */
    protected function fireRemoveUpdatePost($sourceId, $payload)
    {
        $requestHelper = vchelper('Request');
        if ($requestHelper->input('vcv-updatePost') !== '1') {
            return;
        }

        vchelper('Events')->fire('vcv:hub:removePostUpdate:post/' . $sourceId, $sourceId, $payload);
    }

    /**
     * Main plugin action to update post data.
     *
     * @param object $post
     * @param int $sourceId
     * @param array $response
     *
     * @return array
     */
    protected function updatePostData($post, $sourceId, $response)
    {
        ob_start();
        $filterHelper = vchelper('Filters');
        $postTypeHelper = vchelper('PostType');
        $currentUserAccessHelper = vchelper('AccessCurrentUser');
        $requestHelper = vchelper('Request');
        $assetsHelper = vchelper('Assets');
        $previewHelper = vchelper('Preview');

        $dataDecoded = $requestHelper->inputJson('vcv-data');
        $content = $requestHelper->input('vcv-content');
        $content = $filterHelper->fire('setData:updatePostData:content', $content);

        // @codingStandardsIgnoreStart
        // ['vcvPublicUploadUrl'] == 'httpx://domain/wp-content/uploads/visualcomposer-assets/*
        // ['vcvUploadUrl'] == 'httpx://domain/wp-content/uploads/*
        $assetUrl = $assetsHelper->getAssetUrl();
        $assetUrl = str_replace(['http://', 'https://'], '', $assetUrl);
        $content = str_replace(
            [
                'https://' . $assetUrl,
                'http://' . $assetUrl,
            ],
            '|!|vcvAssetsUploadUrl|!|',
            $content
        );

        $previewPost = [];
        $uploadDir = wp_upload_dir();
        $uploadUrl = $uploadDir['baseurl'];
        $uploadUrl = str_replace(['http://', 'https://'], '', $uploadUrl);
        $content = str_replace(
            [
                'https://' . $uploadUrl,
                'http://' . $uploadUrl,
                'https:\/\/' . str_replace('/', '\/', $uploadUrl),
                'http:\/\/' . str_replace('/', '\/', $uploadUrl),
            ],
            '|!|vcvUploadUrl|!|',
            $content
        );
        $post->post_content = $content;
        $isDraftPost = isset($dataDecoded['draft']) && $post->post_status !== 'publish';
        $isPreview = isset($dataDecoded['inherit']);

        if ($isDraftPost) {
            $post->post_status = 'draft';
        } elseif ($isPreview) {
            $previewPost = $previewHelper->generatePreview($post, $sourceId);
        } else {
            if (
                $currentUserAccessHelper->wpAll(
                    [get_post_type_object($post->post_type)->cap->publish_posts, $sourceId]
                )->get()
            ) {
                if ($post->post_status !== 'private' && $post->post_status !== 'future') {
                    $post->post_status = 'publish';
                }
            } else {
                $post->post_status = 'pending';
            }
        }

        // @codingStandardsIgnoreEnd
        //temporarily disable
        kses_remove_filters();
        remove_filter('content_save_pre', 'balanceTags', 50);

        $is_updated = $this->updateSavedPostData($post, $sourceId, $isPreview, $previewPost);

        if ($is_updated) {
            //bring it back once you're done posting
            $postTypeHelper->setupPost($sourceId);

            $responseExtra = $this->getExtraResponse($sourceId, $post);

            // Clearing wp cache
            wp_cache_flush();
            vcevent(
                'vcv:api:postSaved',
                ['sourceId' => $sourceId, 'post' => $post]
            );
            // Flush global $post cache
            $postTypeHelper->setupPost($sourceId);
            $responseExtra['postData'] = $postTypeHelper->getPostData();
        }
        ob_get_clean();

        if ($is_updated) {
            $response = array_merge($response, $responseExtra);
        } else {
            $response = ['status' => false];
        }

        return $response;
    }

    /**
     * Update post data when process post saving.
     *
     * @param object $post
     * @param int $sourceId
     * @param bool $isPreview
     * @param object $previewPost
     *
     * @return bool
     */
    public function updateSavedPostData($post, $sourceId, $isPreview, $previewPost)
    {
        $isUpdated = true;
        if ($isPreview && !empty($previewPost)) {
            // @codingStandardsIgnoreLine
            if ('draft' === $post->post_status || 'auto-draft' === $post->post_status) {
                // @codingStandardsIgnoreLine
                $post->post_status = 'draft';
                $updateResult = wp_update_post($post);
                if (!is_wp_error($updateResult) && $updateResult) {
                    $this->updatePostMeta($sourceId);

                    $previewSourceId = wp_update_post($previewPost[0]);
                    $this->updatePostMeta($previewSourceId);
                } else {
                    $isUpdated = false;
                }
            } else {
                $previewSourceId = wp_update_post($previewPost[0]);
                if (!is_wp_error($previewSourceId) && $previewSourceId) {
                    $this->updatePostMeta($previewSourceId);
                } else {
                    $isUpdated = false;
                }
            }
        } else {
            $updateResult = wp_update_post($post);
            if (!is_wp_error($updateResult) && $updateResult) {
                $this->updatePostMeta($sourceId);
            } else {
                $isUpdated = false;
            }
        }

        return $isUpdated;
    }

    /**
     * Fire up ajax set data event.
     *
     * @param int $sourceId
     * @param object $post
     *
     * @return array
     */
    public function getExtraResponse($sourceId, $post)
    {
        $filterHelper = vchelper('Filters');
        $requestHelper = vchelper('Request');

        return $filterHelper->fire(
            'vcv:dataAjax:setData',
            [
                'status' => true,
            ],
            [
                'sourceId' => $sourceId,
                'post' => $post,
                'data' => $requestHelper->input('vcv-data'),
            ]
        );
    }

    protected function updatePostMeta($sourceId)
    {
        $requestHelper = vchelper('Request');
        $data = $requestHelper->input('vcv-data');

        update_metadata('post', $sourceId, VCV_PREFIX . 'pageContent', $data);

        update_metadata(
            'post',
            $sourceId,
            '_' . VCV_PREFIX . 'pageDesignOptionsData',
            $requestHelper->input('vcv-settings-page-design-options')
        );
        update_metadata(
            'post',
            $sourceId,
            '_' . VCV_PREFIX . 'pageDesignOptionsCompiledCss',
            $requestHelper->input('vcv-settings-page-design-options-compiled')
        );
    }
}
