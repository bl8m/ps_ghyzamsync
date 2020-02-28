<?php
class GhyzamService {

    private $conn;

    public function __construct($connection_params) {
        // Parametri di connessione
        $connectionInfo = array(
            'dbname' => $connection_params['GHYZAMSYNC_REMOTE_DB_NAME'], 
            'user' => $connection_params['GHYZAMSYNC_REMOTE_USERNAME'], 
            'password' => $connection_params['GHYZAMSYNC_REMOTE_PASSWORD'],
            'host' => $connection_params['GHYZAMSYNC_REMOTE_HOST'],
            'driver' => 'sqlsrv'
        );

        // Fix per query su tabelle con colonne DATE/TIME
        date_default_timezone_set('Europe/Rome');

        $config = new \Doctrine\DBAL\Configuration();
        $this->conn = \Doctrine\DBAL\DriverManager::getConnection($connectionInfo, $config);
    }

    // Data quna query, ottiene un array contenente ogni riga della tabella (se get_single è true ottiene la prima riga)
    public function query($sql_statement, $get_single = false) {
        $stmt = $this->conn->query($sql_statement);

        if ($get_single) 
            return $stmt->fetch();

        $resTable = [];
        while ($row = $stmt->fetch()) 
            $resTable[] = $row;

        return $resTable;
    }

    // Esegue una procedura (<nome_procadura>, <array nome_colonna => valore>, <aggiungi nocount>, <return riga singola>)
    public function execProcedure($name, $params = [], $no_count = false, $get_single = true) {
		$sql = '';
		$params_names = [];
		if ($no_count) $sql .= 'SET NOCOUNT ON; ';
		$sql .= 'EXEC ' . $name . ' ';

		if (!empty($params)) {
			foreach ($params as $key => $param)
				$params_names[] = '@' . $key . '=\'' . pSQL($param) . '\'';
			$sql .= implode(', ', $params_names);
		}

		return $this->query($sql, $get_single);
	}

    // Effettua utf8_encode ricorsivo su array
    public function utf8EncodeDeep($array) {
        array_walk_recursive($array, function(&$item, $k) {
            if(!mb_detect_encoding($item, 'utf-8', true)){
                    $item = utf8_encode($item);
            }
        });
        return $array;
    }

    public function closeConnection() {
        $this->conn->close();
    }
}
?>