<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Common\Uri;
use Symfony\Component\Yaml\Yaml;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class FormPlugin
 * @package Grav\Plugin
 */
class FormPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000
    ];

    /**
     * @var bool
     */
    protected $active = false;

    /**
     * @var Form
     */
    protected $form;

    protected $forms = [];

    protected $cache_id = 'plugin-form';

    protected $recache_forms = false;


    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'   => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths'    => ['onTwigTemplatePaths', 0]
        ];
    }

    /**
     * Initialize forms from cache if possible
     */
    public function onPluginsInitialized()
    {
        require_once(__DIR__ . '/classes/form.php');

        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPageProcessed'        => ['onPageProcessed', 0],
            'onPagesInitialized'     => ['onPagesInitialized', 0],
            'onTwigInitialized'      => ['onTwigInitialized', 0],
        ]);

        // Get and set the cache of forms if it exists
        $forms = $this->grav['cache']->fetch($this->cache_id);
        if (is_array($forms)) {
            $this->forms = $forms;


        }

    }

    /**
     * Process forms after page header processing, but before caching
     *
     * @param Event $e
     */
    public function onPageProcessed(Event $e)
    {
        /** @var Page $page */
        $page = $e['page'];
        $page_route = $page->route();

        $header = $page->header();
        if ((isset($header->forms) && is_array($header->forms)) ||
            (isset($header->form) && is_array($header->form))) {

            $page_forms = [];

            // get the forms from the page headers
            if (isset($header->forms)) {
                $page_forms = $header->forms;
            } elseif (isset($header->form)) {
                $page_forms[] = $header->form;
            }

            // Store the page forms in the forms instance
            foreach ($page_forms as $name => $page_form) {
                $form = new Form($page, $name, $page_form);
                $form_array = [$form['name'] => $form];
                if (array_key_exists($page_route, $this->forms)) {
                    $this->forms[$page_route] = array_merge($this->forms[$page_route], $form_array);
                } else {
                    $this->forms[$page_route] = $form_array;
                }

            }

            $this->recache_forms = true;
        }
    }

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     */
    public function onPagesInitialized()
    {
        if ($this->forms) {

            $this->enable([
                'onTwigPageVariables'    => ['onTwigVariables', 0],
                'onTwigSiteVariables'    => ['onTwigVariables', 0],
                'onFormFieldTypes'       => ['onFormFieldTypes', 0]
            ]);

            // Save the current state of the forms to cache
            if ($this->recache_forms) {
                $this->grav['cache']->save($this->cache_id, $this->forms);
            }

            $this->active = true;

            // Handle posting if needed.
            if (!empty($_POST)) {

                $this->enable([
                    'onFormProcessed'       => ['onFormProcessed', 0],
                    'onFormValidationError' => ['onFormValidationError', 0]
                ]);

                $flat_forms = Utils::arrayFlatten($this->forms);

                $form_name = filter_input(INPUT_POST, '__form-name__');

                if (array_key_exists($form_name, $flat_forms)) {
                    $form = $flat_forms[$form_name];
                    $form->post();
                }
            }
        }
    }

    /**
     * Add simple `forms()` Twig function
     */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig_SimpleFunction('forms', [$this, 'getForm'])
        );
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Make form accessible from twig.
     *
     * @param Event $event
     */
    public function onTwigVariables(Event $event =  null)
    {
        if (!$this->active) {
            return;
        }

        if ($event && isset($event['page'])) {
            $page = $event['page'];
        } else {
            $page = $this->grav['page'];
        }

        $page_route = $page->route();

        // get first item for Twig 'form' variable for this page
        if (isset($this->forms[$page_route])) {
            $forms = $this->forms[$page_route];
            $this->grav['twig']->twig_vars['form'] = array_shift($forms);
        }
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        $this->process($form);

        switch ($action) {
            case 'captcha':
                if (isset($params['recaptcha_secret'])) {
                    $recaptchaSecret = $params['recaptcha_secret'];
                } else if (isset($params['recatpcha_secret'])) {
                    // Included for backwards compatibility with typo (issue #51)
                    $recaptchaSecret = $params['recatpcha_secret'];
                } else {
                    $recaptchaSecret = $this->config->get('plugins.form.recaptcha.secret_key');
                }

                // Validate the captcha
                $query = http_build_query([
                    'secret'   => $recaptchaSecret,
                    'response' => $form->value('g-recaptcha-response', true)
                ]);
                $url = 'https://www.google.com/recaptcha/api/siteverify?' . $query;
                $response = json_decode(file_get_contents($url), true);

                if (!isset($response['success']) || $response['success'] !== true) {
                    $this->grav->fireEvent('onFormValidationError', new Event([
                        'form'    => $form,
                        'message' => $this->grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA')
                    ]));
                    $event->stopPropagation();

                    return;
                }
                break;
            case 'ip':
                $label = isset($params['label']) ? $params['label'] : 'User IP';
                $blueprint = $form->value()->blueprints();
                $blueprint->set('form/fields/ip', ['name'=>'ip', 'label'=> $label]);
                $form->setFields($blueprint->fields());
                $form->setData('ip', Uri::ip());
                break;
            case 'message':
                $translated_string = $this->grav['language']->translate($params);
                $vars = array(
                    'form' => $form
                );

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $processed_string = $twig->processString($translated_string, $vars);

                $form->message = $processed_string;
                break;
            case 'redirect':
                $this->grav['session']->setFlashObject('form', $form);
                $this->grav->redirect((string)$params);
                break;
            case 'reset':
                if (Utils::isPositive($params)) {
                    $form->reset();
                }
                break;
            case 'display':
                $route = (string)$params;
                if (!$route || $route[0] != '/') {
                    /** @var Uri $uri */
                    $uri = $this->grav['uri'];
                    $route = $uri->route() . ($route ? '/' . $route : '');
                }

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $twig->twig_vars['form'] = $form;

                /** @var Pages $pages */
                $pages = $this->grav['pages'];
                $page = $pages->dispatch($route, true);

                if (!$page) {
                    throw new \RuntimeException('Display page not found. Please check the page exists.', 400);
                }

                unset($this->grav['page']);
                $this->grav['page'] = $page;
                break;
            case 'save':
                $prefix = !empty($params['fileprefix']) ? $params['fileprefix'] : '';
                $format = !empty($params['dateformat']) ? $params['dateformat'] : 'Ymd-His-u';
                $ext = !empty($params['extension']) ? '.' . trim($params['extension'], '.') : '.txt';
                $filename = !empty($params['filename']) ? $params['filename'] : '';
                $operation = !empty($params['operation']) ? $params['operation'] : 'create';

                if (!$filename) {
                    $filename = $prefix . $this->udate($format) . $ext;
                }

                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $vars = [
                    'form' => $form
                ];

                // Process with Twig
                $filename = $twig->processString($filename, $vars);

                $locator = $this->grav['locator'];
                $path = $locator->findResource('user://data', true);
                $dir = $path . DS . $form->name();
                $fullFileName = $dir. DS . $filename;

                $file = File::instance($fullFileName);

                if ($operation == 'create') {
                    $body = $twig->processString(!empty($params['body']) ? $params['body'] : '{% include "forms/data.txt.twig" %}',
                        $vars);
                    $file->save($body);
                } elseif ($operation == 'add') {
                    if (!empty($params['body'])) {
                        // use body similar to 'create' action and append to file as a log
                        $body = $twig->processString($params['body'], $vars);

                        // create folder if it doesn't exist
                        if (!file_exists($dir)) {
                            mkdir($dir);
                        }

                        // append data to existing file
                        file_put_contents($fullFileName, $body, FILE_APPEND | LOCK_EX);
                    } else {
                        // serialize YAML out to file for easier parsing as data sets
                        $vars = $vars['form']->value()->toArray();

                        foreach ($form->fields as $field) {
                            if (isset($field['process']) && isset($field['process']['ignore']) && $field['process']['ignore']) {
                                unset($vars[$field['name']]);
                            }
                        }

                        if (file_exists($fullFileName)) {
                            $data = Yaml::parse($file->content());
                            if (count($data) > 0) {
                                array_unshift($data, $vars);
                            } else {
                                $data[] = $vars;
                            }
                        } else {
                            $data[] = $vars;
                        }

                        $file->save(Yaml::dump($data));
                    }

                }
                break;
        }
    }

    /**
     * Handle form validation error
     *
     * @param  Event $event An event object
     */
    public function onFormValidationError(Event $event)
    {
        $form = $event['form'];
        if (isset($event['message'])) {
            $form->message_color = 'red';
            $form->message = $event['message'];
        }

        $uri = $this->grav['uri'];
        $route = $uri->route();

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $twig->twig_vars['form'] = $form;

        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($route, true);

        if ($page) {
            unset($this->grav['page']);
            $this->grav['page'] = $page;
        }

        $event->stopPropagation();
    }

    /**
     * Get list of form field types specified in this plugin. Only special types needs to be listed.
     *
     * @return array
     */
    public function getFormFieldTypes()
    {
        return [
            'display' => [
                'input@' => false
            ],
            'spacer'  => [
                'input@' => false
            ],
            'captcha' => [
                'input@' => false
            ]
        ];
    }

    /**
     * Process a form
     *
     * Currently available processing tasks:
     *
     * - fillWithCurrentDateTime
     *
     * @param Form $form
     *
     * @return bool
     */
    protected function process($form)
    {
        foreach ($form->fields as $field) {
            if (isset($field['process'])) {
                if (isset($field['process']['fillWithCurrentDateTime']) && $field['process']['fillWithCurrentDateTime']) {
                    $form->setData($field['name'], gmdate('D, d M Y H:i:s', time()));
                }
            }
        }
    }

    /**
     * Create unix timestamp for storing the data into the filesystem.
     *
     * @param string $format
     * @param int    $utimestamp
     *
     * @return string
     */
    private function udate($format = 'u', $utimestamp = null)
    {
        if (is_null($utimestamp)) {
            $utimestamp = microtime(true);
        }

        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);

        return date(preg_replace('`(?<!\\\\)u`', \sprintf('%06d', $milliseconds), $format), $timestamp);
    }

    /**
     * function to get a specific form
     *
     * @param null|array $data optional page `route` and form `name` in the format ['route' => '/some/page', 'name' => 'form-a']
     *
     * @return null|Form
     */
    public function getForm($data = null)
    {
        $page_route = null;
        $form_name = null;

        if (is_array($data)) {
            if (isset($data['route'])) {
                $page_route = $data['route'];
            }
            if (isset($data['name'])) {
                $form_name = $data['name'];
            }
        }

        // Accept no page route, or @self, or self@, or '' for current page
        if (!$page_route || $page_route == '@self' || $page_route == 'self@' || $page_route == '') {
            $page_route = $this->grav['page']->route();

            // fallback using current URI if page not initialized yet
            if (!$page_route) {
                $path = $this->grav['uri']->path(); // Don't trim to support trailing slash default routes
                $path = $path ?: '/';
                $page_route = $this->grav['pages']->dispatch($path)->route();
            }
        }

        // if no form name, use the first firm found
        if (!$form_name) {
            if (isset($this->forms[$page_route])) {
                $forms = $this->forms[$page_route];
                $first_form = array_shift($forms);
                $form_name = $first_form['name'];
            }
        }

        // return the form you are looking for if available
        if (isset($this->forms[$page_route][$form_name])) {
            return $this->forms[$page_route][$form_name];
        }

        return null;
    }

}
