<?php
use PrestaShop\PrestaShop\Adapter\Entity\Category;
use PrestaShop\PrestaShop\Adapter\Entity\Manufacturer;
use PrestaShop\PrestaShop\Adapter\Entity\Tax;

include(__DIR__ . '/GhyzamService.php');

class GhyzamCatalog {
    private $service;
    private $default_document_id = 'PS_B2C';
    private $update_last_edit_look = '-1 days';
    private $update_deleted = true;


    public $log = [];


    // Associa i metodi di pagamento di Prestashop a campi di RD04
    private $paymentsToRDVals = [
        'ps_checkpayment' => ['toPayment' => 'CA', 'toPaymentCode' => 'CA', 'toStatus' => 1], // Assegno
        'ps_wirepayment'  => ['toPayment' => 'BB', 'toPaymentCode' => 'BB10GGDF', 'toStatus' => 1], // Bonifico bancario
        'ps_??payment'    => ['toPayment' => 'RD', 'toPaymentCode' => 'CC', 'toStatus' => 4], // Carta di credito
        'ps_??payment'    => ['toPayment' => 'RD', 'toPaymentCode' => 'PAYPAL', 'toStatus' => 4], // PayPal
    ];
    public static $log_file = __DIR__ . '/../module_log.log';

    public function __construct($connection_params) {
        $this->service = new GhyzamService($connection_params);
    }

    // Inserisce (o modifica) un prodotto nel catalogo di Prestashop
    public function newPSProduct($rd_product, $update_mode = false, $product_id = 0) {
        
        $product = null;

        if ($update_mode)
            $product = new Product($product_id);
        else
            $product = new Product();

        if (Validate::isEan13($this->returnDefaultIfNotSet($rd_product, 'Barcode'))) {
            $product->ean13 = $this->returnDefaultIfNotSet($rd_product, 'Barcode');
        }

        $product->name = [(int)Configuration::get('PS_LANG_DEFAULT') => $this->returnDefaultIfNotSet($rd_product, 'Descrizione')];
        $product->reference = $this->returnDefaultIfNotSet($rd_product, 'CodiceArticolo');
        $product->description = $this->returnDefaultIfNotSet($rd_product, 'SchedaDescrittiva');
        $product->description_short = $this->returnDefaultIfNotSet($rd_product, 'DescrizioneWeb');
        $product->link_rewrite = [(int)Configuration::get('PS_LANG_DEFAULT') 
            => Tools::link_rewrite($this->returnDefaultIfNotSet($rd_product, 'Descrizione'))];

        if (Validate::isPrice($this->returnDefaultIfNotSet($rd_product, 'PrezzoPubblicoNetto'))) {
            $product->price = (float)$this->returnDefaultIfNotSet($rd_product, 'PrezzoPubblicoNetto', 0);
        }

        $product->id_manufacturer = $this->newPSManufacturerIfNotInDB(
            $this->returnDefaultIfNotSet($rd_product, 'NomeProduttore', 'N.D.', true)
        );
        
        $product->id_category_default = $this->newPSCategoryIfNotInDB(
            $this->returnDefaultIfNotSet($rd_product, 'Categoria', 'Nessuna categoria', true)
        );

        $product->id_tax_rules_group = $this->newPSTaxIfNotInDB(
            $this->returnDefaultIfNotSet($rd_product, 'Iva', 22, true)
        );

        // Non funziona?
        // $product->quantity = (int)$this->returnDefaultIfNotSet($rd_product, 'DisponibilitaSede', 0);
        $product->quantity = (int)$this->returnDefaultIfNotSet($rd_product, 'QtaDispon', 0);


        $product->show_price = 1;
        $product->minimal_quantity = 1;
        $product->available_now = true;
        $product->addToCategories([$product->id_category_default]);
        // $product->on_sale = 0;

        if ($update_mode)
            $product->save();
        else
            $product->add();

            
        Shop::setContext(Shop::CONTEXT_SHOP, null);
        StockAvailable::setQuantity($product->id, 
            (int)Tools::getValue('id_product_attribute'), 
            (int)$this->returnDefaultIfNotSet($rd_product, 'QtaDispon', 0));


        /*
        $adminProductWrapper->processQuantityUpdate($product, $_POST['qty_0']);
        */
        
        if (!$update_mode)
            $this->addPSProductImage($product->id, $this->returnDefaultIfNotSet($rd_product, 'UrlImmagine', false, true));

        return $product;
    }

    // Ottiene una lista di prodotti dal DB remoto
    public function getRDRemoteProducts($check_updated = false) {
        $opt = [];
        if ($check_updated)
            $opt = ['FlagUpdated' => 1];
        return $this->service->execProcedure('AGE_SP_ESTRAI_DATI_ARTICOLI', $opt, true, false);
    }

    // Ottiene un array contenete tutti i CodiceArticolo (reference) da RD04
    public function getRDReferences() {
        $references = [];
        $ref_db = $this->service->query('SELECT [CodiceArticolo] FROM [age_listini_web_dettaglio]');
        foreach ($ref_db as $single_ref_db)
            $references[] = $this->returnDefaultIfNotSet($single_ref_db, 'CodiceArticolo');
        return $references;
    }

    // Ottiene una lista di prodotti selezionati per campo reference (CodiceArticolo) dal database remoto
    public function getRDSelectedRemoteProducts($references, $arr_key = null) {
        if (count($references) < 1) return $references;

        $len = count($references) - 1;
        $ref_string = '';
        foreach ($references as $key => $ref)
            $ref_string .= "'" . pSQL(is_null($arr_key) ? $ref : $ref[$arr_key]) . "'" . ($key < $len ? ', ' : '');

        // TODO: Stored procedure?
        return $this->service->query('SELECT [rec_id],[ultima_modifica],[creato_il],[CodiceAzienda],[CodiceListino],[Riga],[CodiceArticolo]
        ,[Descrizione],[CodiceProduttore],[UnitaDiMisura],[Produttore],[NomeProduttore],[Ean13],[Code32],[PrezzoPubblico],[CodiceIva],[Iva],[PrezzoPubblicoNetto],[TipoCalcoloCosto],[Sconto],[CostoAcquisto],[ScontoAcquistoLordo],[ScontoAcquistoNetto],[PercentualeRicarico],[ListinoWeb]
        ,[ScontoLordo],[ScontoNetto],[MinimoOrdinabile],[GruppoMinimo],[Note],[PercentualeRicaricoB],[ListinoWebB],[ScontoLordoB],[ScontoNettoB]
        ,[CodiceListinoRiferimento],[NoteWeb],[Categoria],[ListinoWebCopia],[ListinoWebCopiaB],[NonPubblicare],[DisponibilitaSede]
        ,[PrezzoFornitore],[ScontoAcquistoLordoPrezzoFornitore],[ScontoAcquistoNettoPrezzoFornitore],[Barcode],[DescrizioneWeb],[SchedaDescrittiva]
        ,[UrlImmagine],[ProdottoPrenotabile] FROM [age_listini_web_dettaglio] WHERE [CodiceArticolo] IN (' . $ref_string . ')');
    }

    // Crea un nuovo record categoria se non esiste nel DB
    public function newPSCategoryIfNotInDB($category_name) {
        $id_category = $this->getPSCategoryByName($category_name);
        if ($id_category === false) {
            $category = new Category();
            $category->id_parent = Configuration::get('PS_HOME_CATEGORY');
            $category->name = [(int)Configuration::get('PS_LANG_DEFAULT') => empty($category_name) ? 'Nessuna categoria' : $category_name];
            $category->link_rewrite = [(int)Configuration::get('PS_LANG_DEFAULT') => empty($category_name) ? 'nessuna-categoria' : Tools::link_rewrite($category_name)];
            $category->active = true;
            $category->add();
            $id_category = $category->id;
        }
        return $id_category;
    }

    // Crea un nuovo record tassa se non esiste nel DB
    public function newPSTaxIfNotInDB($tax_rate) {
        $id_tax = $this->getPSTaxByVal((float)$tax_rate);
        if ($id_tax === false) {
            $tax = new Tax();
            $tax->name = [(int)Configuration::get('PS_LANG_DEFAULT') => 'Iva ' . $tax_rate . '%'];
            $tax->rate = $tax_rate;
            $tax->active = true;
            // TODO: Aggiungere tassa alle aliquote iva
            $tax->add();
            $id_tax = $tax->id;
        }
        return $id_tax;
    }

    // Crea un nuovo record produttore se non esiste nel DB
    public function newPSManufacturerIfNotInDB($manufacturer_name) {
        $id_manufacturer = $this->getPSManufacturerByName($manufacturer_name);
        if ($id_manufacturer === false) {
            $manufacturer = new Manufacturer();
            $manufacturer->name = $manufacturer_name;
            $manufacturer->active = true;
            $manufacturer->add();
            $id_manufacturer = $manufacturer->id;
        }
        return $id_manufacturer;
    }

    // Crea e carica una nuova immagine e la associa al prodotto
    public function addPSProductImage($product_id, $image_url) {
        if ($image_url != false) {
            $shops = Shop::getShops(true, null, true);
            $image = new Image();
            $image->id_product = $product_id;
            $image->position = Image::getHighestPosition($product_id) + 1;
            $image->cover = true; // or false;
            if (($image->validateFields(false, true)) === true &&
            ($image->validateFieldsLang(false, true)) === true && $image->add()) {
                $image->associateTo($shops);
                if (!$this->copyImg($product_id, $image->id, $image_url, 'products', false)) {
                    $image->delete();
                }
            }
        }
    }

    // Ottiene un id prodotto per campo reference
    public function getPSProductByRefrence($reference) {
        $row = Db::getInstance()->getRow('SELECT `id_product` FROM ' . _DB_PREFIX_ . 'product WHERE `reference` = \'' . pSQL($reference) . '\'');

        return $this->returnDefaultIfNotSet($row, 'id_product', false);
    }

    // Ottiene un id ordine per campo reference
    public function getPSOrderByRefrence($reference) {
        $row = Db::getInstance()->getRow('SELECT `id_order` FROM ' . _DB_PREFIX_ . 'orders WHERE `reference` = \'' . pSQL($reference) . '\'');

        return $this->returnDefaultIfNotSet($row, 'id_order', false);
    }

    // Ottiene un id produttore per campo name
    public function getPSManufacturerByName($name) {
        $row = Db::getInstance()->getRow('SELECT `id_manufacturer` FROM ' . _DB_PREFIX_ . 'manufacturer WHERE `name` = \'' . pSQL($name) . '\'');

        return $this->returnDefaultIfNotSet($row, 'id_manufacturer', false);
    }

    // Ottiene un id categoria per campo name
    public function getPSCategoryByName($name) {
        $row = Db::getInstance()->getRow('SELECT `id_category` FROM ' . _DB_PREFIX_ . 'category_lang WHERE `name` = \'' . pSQL($name) . '\'');

        return $this->returnDefaultIfNotSet($row, 'id_category', false);
    }

    // Ottiene un id tassa per valore
    public function getPSTaxByVal($rate) {
        $row = Db::getInstance()->getRow('SELECT `id_tax` FROM ' . _DB_PREFIX_ . 'tax WHERE `rate` = ' . pSQL($rate));

        return $this->returnDefaultIfNotSet($row, 'id_tax', false);
    }

    // Esegue il sync dei prodotti da RD04 a Prestashop
    public function syncProducts($sync_all = false) {
        $synced = $deleted = [];
        $sync_since = date('Y-m-d', strtotime($this->update_last_edit_look));
        // SP da chiamare a inizio importazione
        $this->service->execProcedure('AGE_SP_INIZIO_IMPORTAZIONE');
        $rd_products = $this->getRDRemoteProducts(!$sync_all);
        foreach ($rd_products as $product) {
            $ps_product_id = $this->getPSProductByRefrence($product['CodiceArticolo']);
            
            if ($ps_product_id) {
                // Update prodotto
                $this->newPSProduct($product, true, $ps_product_id);
            } else {
                // Nuovo prodotto
                $this->newPSProduct($product, false);
            }
            $synced[] = $product['CodiceArticolo'];
        }

        if ($this->update_deleted) {
            // Ottengo il databse di prestashop ($id_lang, $start, $limit, $order_by, $order_way)
            $all_ps_products = Product::getProducts((int)Context::getContext()->language->id, 0, 1000000000, 'id_product', 'DESC');
            // Ottengo i reference dal database di RD04
            $rd_references = $this->getRDReferences();
            foreach ($all_ps_products as $ps_product) {
                $ps_reference = $ps_product['reference'];
                if (!in_array($ps_reference, $rd_references)) {
                    $p = new Product($ps_product['id_product']);
                    $p->delete();
                    $deleted[] = $ps_reference;
                }
            }
        }

        // SP da chiamare a fine importazione
        $this->service->execProcedure('AGE_SP_FINE_IMPORTAZIONE');

        return ['tot_sync' => count($rd_products), 'synced_ref' => $synced, 
                'tot_del' => count($deleted), 'deleted_ref' => $deleted, 
                'synced_since' => ($sync_all ? 'always' : $sync_since)];
    }

    // TODO Esegue il sync dello stato degli ordini attivi da RD04 a Prestashop
    public function syncOrders() {
        $rd_orders = $this->service->query('SELECT * FROM [age_ordini_clienti] WHERE StatoDocumento < 7');
        foreach ($rd_orders as $rd_order) {
            $ps_ref = $this->getPSOrderByRefrence($rd_order['Note1']);
            if ($ps_ref) {
                $ps_order = new Order($ps_ref);
                print_r($ps_order);
            }
        }
    }

    // Aggiorna la giacenza dei prodotti nel magazzino di PrestaShop con la giacenza in RD04
    // seleziona solo i prodotti nel carrello corrente del cliente
    public function updatePSCartItemsQuantities() {
        $cart = Context::getContext()->cart->getProducts();

        $remote_prods = $this->getRDSelectedRemoteProducts($cart, 'reference');

        foreach ($remote_prods as $prod) {
            StockAvailable::setQuantity($this->getPSProductByRefrence($this->returnDefaultIfNotSet($prod, 'CodiceArticolo')), 
                (int)Tools::getValue('id_product_attribute'), 
                (int)$this->returnDefaultIfNotSet($prod, 'DisponibilitaSede', 0));
        }

        return $remote_prods;
    }

    // Aggiunge un cliente a RD04
    public function addRDCustomer($ps_user) {
        return $this->service->execProcedure('ERP_SP_UPDATE_AGE_ANAGRAFICHE', [
            'Azione' => 'UPD', 
            'CodiceAnagrafica' => $this->PSuidToRDuid($ps_user->id),
            'Email' => $ps_user->email, 
            'RagioneSociale' => sprintf('%s %s', $ps_user->firstname, $ps_user->lastname),
            'Note' => 'Importato da PrestaShop'
        ], true);
    }

    // Aggiunge un indirizzo (e relative info) a RD04
    public function updateRDClientAddresses($address) {
        $nazione = $this->service->query('SELECT * FROM [age_tabella_nazioni] WHERE Nazione = \'' . pSQL($address->country) . '\'', true);
        $comune = $this->service->query('SELECT * FROM [age_tabella_comuni] WHERE Comune = \'' . pSQL($address->city) . '\'', true);

        $test_addr = $this->service->query('SELECT TOP (1000) [rec_id], [ultima_modifica], [CodiceAnagrafica], [RagioneSociale], [Riga]
            FROM [TotUpB2B_dev].[dbo].[age_anagrafiche_destinazioni] 
            WHERE CodiceAnagrafica = \'' . pSQL($this->PSuidToRDuid($address->id_customer)) . '\' 
            AND Indirizzo = \'' . pSQL($address->address1) . '\' 
            AND Indirizzo1 = \'' . pSQL($address->address2) . '\' 
            AND Cap = \'' . pSQL($address->postcode) . '\'', true);
        $addr_id = $this->returnDefaultIfNotSet($test_addr, 'rec_id', false);

        $anagrafiche_destinazioni = [
            'Azione' => 'UPD', 
            'CodiceAnagrafica' => $this->PSuidToRDuid($address->id_customer),
            'RagioneSociale' => sprintf('%s %s', $address->firstname, $address->lastname),
            'RagioneSociale1' => $address->company,
            'Indirizzo' => $address->address1,
            'Indirizzo1' => $address->address2,
            'PartitaIva' => $address->vat_number,
            'Telefono' => $address->phone,
            'Cap' => $address->postcode,
            'CodiceNazione' => $this->returnDefaultIfNotSet($nazione, 'CodiceNazione'),
            'Localita' => $address->city,
            'CodiceComune' => $this->returnDefaultIfNotSet($comune, 'CodiceComune')
        ];

        if ($addr_id) {
            $anagrafiche_destinazioni['rec_id'] = $addr_id;
            $anagrafiche_destinazioni['riga'] = $this->returnDefaultIfNotSet($test_addr, 'Riga', false);
        }

        $result = $this->service->execProcedure('ERP_SP_UPDATE_AGE_ANAGRAFICHE_DESTINAZIONI', $anagrafiche_destinazioni, true);

        $regex_success = preg_match('/.*\..*\.(.*)\..*/', $result['OK'], $match);
        if ($regex_success) $result['rec_id'] = $match[1];

        return $result;
    }

    // Crea un nuovo ordine su RD04
    public function newRDOrder($ps_order, $ps_user) {
        $results = [];

        /* Stati ordine in base al metodo di pagamento utilizzato:
            1) inserito e non pagato > nel caso di bonifico, o in generale di pagamento offline
            2) in download da sede
            3) scaricato da sede > che noi indicheremo come ricevuto
            4) inserito e pagato > nel caso di pagamento online concluso con successo
            6) spedito
            9) annullato
        */

        // Salvo o aggiorno l'indirizzo
        $ps_address = new Address($ps_order->id_address_delivery);
        $rd_address = $this->updateRDClientAddresses($ps_address);

        // Creo la testata dell'ordine
        $order_header = $this->service->execProcedure('ERP_SP_UPDATE_AGE_ORDINI_CLIENTI', [
            'Azione' => 'INS', 
            'CodiceAnagrafica' => $this->PSuidToRDuid($ps_user->id), 
            'CodiceDestinazione' => $rd_address['rec_id'], 
            'IdDocumento' => $this->default_document_id, 
            'ImponibileMerce' => $ps_order->total_products,
            'TotaleDocumento' => $ps_order->total_products_wt,
            'NettoPagare' => $ps_order->total_products_wt,
            'Note1' => $ps_order->reference,
            'CodicePagamento' => isset($this->paymentsToRDVals[$ps_order['module']]) ? 
                $this->paymentsToRDVals[$ps_order['module']]['toPaymentCode'] : 'AVV',
            'StatoDocumento' => isset($this->paymentsToRDVals[$ps_order['module']]) ? 
                $this->paymentsToRDVals[$ps_order['module']]['toStatus'] : 1
        ], true);

        // Aggiungo gli articoli all'ordine
        foreach ($ps_order->product_list as $product) {
		    $item = $this->service->execProcedure('ERP_SP_UPDATE_AGE_ORDINI_CLIENTI_DETTAGLIO', [
                //  'CodiceAzienda' => $user_id, 
                    'CodiceAnagrafica' => $this->PSuidToRDuid($ps_user->id),
                    'Azione' => 'INS', 
                	'IdDocumento' => $this->default_document_id, 
                	'NumeroDocumento' => $order_header['NumeroDocumento'],
                    'CodiceArticolo' => $product['reference'],
                    'Descrizione' => $product['name'], 
                    'Quantita' => $product['cart_quantity'],
                    'ValoreUnitario' => $product['price'],
                // TODO: 'CodiceIva' => $ps_order['CodiceIva'], 
                    'Iva' => $product['rate'], 
                    'MinimoOrdinabile' => $product['minimal_quantity'],

                    'LordoMerceUnitario' => $product['price_wt'],
                    'LordoMerce' => $product['total_wt'],
                    'ValoreUnitarioNetto' => $product['price'],
                    'NettoRiga' => $product['total'],
                    'ValoreUnitarioNetto' => $product['price'],
                    'Imponibile' => $product['total'],
                    'Imposta' => $product['total_wt'] - $product['total']
                ], true);
            
		    unset($item->versione);
            $results[] = $item;
        }

		return ['header' => $this->service->utf8EncodeDeep($order_header), 'items' => $this->service->utf8EncodeDeep($results)];
	}

    // Converte l'id numerico di Prestashop in customer ID di RD04
    private function PSuidToRDuid($user_id) {
        return sprintf('PS%05d', (int)$user_id); // Ex. 'PS0000X'
    }

    // Restituisce una stringa di default se la chiave dell'array non esiste
    private function returnDefaultIfNotSet ($array, $key, $def = '', $check_empty = false) {
        return $array && is_array($array) && array_key_exists($key, $array) ? ($check_empty && empty($array[$key]) ? $def : $array[$key]) : $def;
    }

    // Salva un'immagine da URL nell'apposita directory di prestashop
    private function copyImg($id_entity, $id_image, $url, $entity = 'products', $regenerate = true) {
        $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));
    
        switch ($entity) {
            default:
            case 'products':
                $image_obj = new Image($id_image);
                $path = $image_obj->getPathForCreation();
                break;
            case 'categories':
                $path = _PS_CAT_IMG_DIR_ . (int) $id_entity;
                break;
            case 'manufacturers':
                $path = _PS_MANU_IMG_DIR_ . (int) $id_entity;
                break;
            case 'suppliers':
                $path = _PS_SUPP_IMG_DIR_ . (int) $id_entity;
                break;
        }
        $url = str_replace(' ', '%20', trim($url));
    
        // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
        if (!ImageManager::checkImageMemoryLimit($url))
            return false;
    
        // 'file_exists' doesn't work on distant file, and getimagesize makes the import slower.
        // Just hide the warning, the processing will be the same.
        if (Tools::copy($url, $tmpfile)) {
            @ImageManager::resize($tmpfile, $path . '.jpg');
            $images_types = ImageType::getImagesTypes($entity);
    
            if ($regenerate)
                foreach ($images_types as $image_type) {
                    ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'], $image_type['height']);
                    if (in_array($image_type['id_image_type'], $watermark_types))
                        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                }
        } else {
            unlink($tmpfile);
            return false;
        }
        unlink($tmpfile);
        return true;
    }

    public function end() {
        $this->service->closeConnection();
    }
}
?>
