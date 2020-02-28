<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

include(__DIR__ . '/classes/GhyzamCatalog.php');

class GhyzamSync extends Module
{
    public function __construct() {
        $this->name = 'ghyzamsync';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Bloom Design';
        $this->need_instance = 1;

        // Modulo conforme a bootstrap (PrestaShop 1.6)
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Ghyzam Sync for RD04');
        $this->description = $this->l('Module for syncronization with RD04 Erp');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    // Installazione e disinstallazione
    public function install() {
        Configuration::updateValue('GHYZAMSYNC_LIVE_MODE', true);

        $this->writeLog('Install called');

        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionCartSave') &&
            $this->registerHook('actionCustomerAccountAdd');
    }

    public function uninstall() {
        Configuration::deleteByName('GHYZAMSYNC_LIVE_MODE');

        return parent::uninstall();
    }

    // Pagina principale di config, carica il form di configurazione e salva i dati in POST, se necessario
    public function getContent() {
        // Messaggi di output
        $output = '';

        // Salva i dati in POST, se necessario
        if (((bool)Tools::isSubmit('submit' . $this->name)) == true) {
            $this->postProcess();

            $output .= 'Submit OK';
        }

        // Carica il form di configurazione
        return $output . $this->displayForm();
    }

    // Crea il form di configurazione del modulo
    protected function displayForm() {
        // Lingua di default
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Array di campi da generare nel form
        $fieldsForm['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Remote Host'),
                    'name' => 'GHYZAMSYNC_REMOTE_HOST',
                    'size' => 40,
                    'desc' => $this->l('Remote address, port. Example: 1.2.3.4, 3344'),
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Database Name'),
                    'name' => 'GHYZAMSYNC_REMOTE_DB_NAME',
                    'size' => 40,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Username'),
                    'name' => 'GHYZAMSYNC_REMOTE_USERNAME',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Password'),
                    'name' => 'GHYZAMSYNC_REMOTE_PASSWORD',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Endpoint token'),
                    'name' => 'GHYZAMSYNC_FRONT_TOKEN',
                    'size' => 50,
                    'desc' => $this->l('Token to access the frontend endpoint. Leave empty to disable token verification.'),
                    'required' => false
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Save log'),
                    'name' => 'GHYZAMSYNC_SAVE_LOG',
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->l('Enabled')
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->l('Disabled')
                        ]
                    ]
                ]
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helperForm = new HelperForm();

        // Module, token e currentIndex
        $helperForm->module = $this;
        $helperForm->name_controller = $this->name;
        $helperForm->token = Tools::getAdminTokenLite('AdminModules');
        $helperForm->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Lingua
        $helperForm->default_form_language = $defaultLang;
        $helperForm->allow_employee_form_lang = $defaultLang;

        // Titolo e toolbar
        $helperForm->title = $this->displayName;
        $helperForm->show_toolbar = true;   // false -> remove toolbar
        $helperForm->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helperForm->submit_action = 'submit'.$this->name;
        $helperForm->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Carica i valori correnti
        $helperForm->fields_value = $this->getConfigFormValues();

        // Indirizzo endpoint principale
        $this->front_office_URL = '/module/' . $this->name . '/endpoint?fn=fn_name' . 
            (empty($helperForm->fields_value['GHYZAMSYNC_FRONT_TOKEN']) ? '' : '&token=' . $helperForm->fields_value['GHYZAMSYNC_FRONT_TOKEN']);

        return $helperForm->generateForm([$fieldsForm]) . 
        '<div>' . $this->l('Front office url: ') . '<a href="' . $this->front_office_URL . '">' . $this->front_office_URL . '</a></div>';
    }

    // Lista dei campi dell'array con valore attuale
    public function getConfigFormValues() {
        return array(
            'GHYZAMSYNC_REMOTE_HOST'     => Configuration::get('GHYZAMSYNC_REMOTE_HOST'),
            'GHYZAMSYNC_REMOTE_DB_NAME'  => Configuration::get('GHYZAMSYNC_REMOTE_DB_NAME'),
            'GHYZAMSYNC_REMOTE_USERNAME' => Configuration::get('GHYZAMSYNC_REMOTE_USERNAME'),
            'GHYZAMSYNC_REMOTE_PASSWORD' => Configuration::get('GHYZAMSYNC_REMOTE_PASSWORD'),
            'GHYZAMSYNC_FRONT_TOKEN'     => Configuration::get('GHYZAMSYNC_FRONT_TOKEN'),
            'GHYZAMSYNC_SAVE_LOG'        => Configuration::get('GHYZAMSYNC_SAVE_LOG')
        );
    }

    // Salva i dati del form
    protected function postProcess() {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    // Hook nuovo account
    public function hookActionCustomerAccountAdd($params) {
        $rd_service = new GhyzamCatalog($this->getConfigFormValues());
        $res = $rd_service->addRDCustomer($params['newCustomer']);
        $rd_service->end();

        $this->writeLog('hookActionCustomerAccountAdd called', $res);
    }

    // Hook per ogni azione eseguita sul carrello
    public function hookActionCartSave() {
        if (!$this->active || !Validate::isLoadedObject($this->context->cart) || !Tools::getIsset('id_product')) return false;

        $rd_service = new GhyzamCatalog($this->getConfigFormValues());
        $prod_updated = $rd_service->updatePSCartItemsQuantities();
        $rd_service->end();

        $this->writeLog('hookActionCartSave called', $prod_updated);

        return false;
    }

    // Hook nuovo o modifica ordine
    public function hookActionValidateOrder($params) {
        $rd_service = new GhyzamCatalog($this->getConfigFormValues());
        $order_result = $rd_service->newRDOrder($params['order'], $params['customer']);
        $rd_service->end();

        $this->writeLog('actionValidateOrder called', $order_result);
    }

    // Salva sul file di log
    public function writeLog($log, $result = false) {
        if (Configuration::get('GHYZAMSYNC_SAVE_LOG') == true) {
            $fp = fopen(GhyzamCatalog::$log_file, 'a');
            fwrite($fp, date('[d.m.Y H:i:s] ') . $log . ($result ? '. Result: ' . json_encode($result) : '') . "\n");
            fclose($fp);
        }
    }
}