<?php
Class ghyzamsyncEndpointModuleFrontController extends ModuleFrontController
{
    // Si deve eseguire l'autenticazione per accedere all'endpoint?
    // public $auth = true;
    // public $guestAllowed = false;

    public function init() {
        parent::init();
    }

    // Contenuto pagina web
    public function display() {
        parent::initContent();
        $config = GhyzamSync::getConfigFormValues();
        $catalog_service = new GhyzamCatalog($config);

        $result = ['status' => 'no-sel', 'message' => 'Nothing selected'];

        if (empty($config['GHYZAMSYNC_FRONT_TOKEN']) || isset($_GET['token']) && ($_GET['token'] == $config['GHYZAMSYNC_FRONT_TOKEN'])) {
            if (isset($_GET['fn'])) {
                switch ($_GET['fn']) {
                    case 'syncProducts':
                        $f_result = $catalog_service->syncProducts();
                        $result['status'] = 'ok';
                        $result['message'] = 'Synced latest products successfully';
                        $result['result'] = $f_result;
                        $result['log'] = $catalog_service->log;
                        break;
                    case 'syncAllProducts':
                        $f_result = $catalog_service->syncProducts(true);
                        $result['status'] = 'ok';
                        $result['message'] = 'Synced all products successfully';
                        $result['result'] = $f_result;
                        break;
                    // TODO: caso update ordini?
                    case 'syncOrders':
                        $f_result = $catalog_service->syncOrders();
                        $result['status'] = 'ok';
                        $result['message'] = 'Synced pending orders successfully';
                        $result['result'] = $f_result;
                        break;
                    default:
                        $result['status'] = 'no-fn';
                        $result['message'] = 'Selected function doesn\'t exist';
                }
            }
        } else {
            $result = ['status' => 'no-token', 'message' => 'Wrong or no token supplied'];
        }
        
        $catalog_service->end();

        GhyzamSync::writeLog('Endpoint called', $result);
        header('Content-type: application/json');
        echo json_encode($result);
    }
}