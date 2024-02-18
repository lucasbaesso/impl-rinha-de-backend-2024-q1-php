<?php
    // get the environment variables
    $db_host = getenv('DB_HOST');
    $db_port = getenv('DB_PORT');
    $db_name = getenv('DB_NAME');
    $db_user = getenv('DB_USER');
    $db_pass = getenv('DB_PASS');

    function response($data, $status = 200) {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    $pdo = new PDO("pgsql:host=$db_host;port=$db_port;dbname=$db_name", $db_user, $db_pass, array(PDO::ATTR_PERSISTENT => true));

    // POST /clientes/{id}/transacoes
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/^\/clientes\/(\d+)\/transacoes$/', $_SERVER['REQUEST_URI'], $matches)
    ) {
        $id = $matches[1];
        //get post data
        $data = json_decode(file_get_contents('php://input'), true);

        $valor = $data['valor'];
        $tipo = $data['tipo'];
        $descricao = $data['descricao'];

        // valor must be not null and an integer higher than 0 not decimal
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || $valor <= 0) {
            $response = [
                'error' => 'Valor não aceito'
            ];
            response($response, 422);
        }
        //tipo must be char 'c' or 'd'
        if ($tipo !== 'c' && $tipo !== 'd') {
            $response = [
                'error' => 'Tipo não aceito'
            ];
            response($response, 422);
        }
        //descricao must be not null and a string with length between 1 and 10
        $descricaoLength = strlen($descricao);
        if ($descricaoLength < 1 || $descricaoLength > 10) {
            $response = [
                'error' => 'Descrição não aceita'
            ];
            response($response, 422);
        }
        
        $binds = [];
        if($tipo === 'c'){
            $updateSql = "UPDATE clientes SET saldo = saldo + ? WHERE id = ? RETURNING *";
            $binds = [$valor, $id];
        } else {
            //if tipo is 'd' than write an sql query to update the saldo of the cliente subtracting the valor and if the saldo is less than the limite return an error
            $updateSql = "UPDATE clientes SET saldo = saldo - ? WHERE id = ? AND saldo - ? >= -ABS(limite) RETURNING limite, saldo";
            $binds = [$valor, $id, $valor];
        }
        $sql = "WITH updated AS ($updateSql)
            INSERT INTO transacoes (clienteid, valor, tipo, descricao, ultimolimite, ultimosaldo)
                SELECT ?, ?, ?, ?, updated.limite, updated.saldo
                FROM updated
                RETURNING ultimolimite, ultimosaldo;";

        // $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($binds, [$id, $valor, $tipo, $descricao]));
        $result = $stmt->fetchAll();
        // $pdo->commit();

        // check if query returned any result
        if(count($result) === 0){
            $response = [
                'error' => 'Limite excedido'
            ];
            response($response, 422);
        }

        // get the result from the query
        $row = $result[0];
        $novoSaldo = $row['ultimosaldo'];
        $limite = $row['ultimolimite'];

        $response = [
            'id' => $id,
            'ok' => true,
            'limite' => $limite,
            'saldo' => $novoSaldo,
        ];
        response($response);

    }

    // GET /clientes/{id}/extrato
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/clientes\/(\d+)\/extrato$/', $_SERVER['REQUEST_URI'], $matches)
    ) {
        $id = $matches[1]; 

        $sql = "SELECT *, now() as datahora_extrato
            FROM transacoes
            WHERE clienteid = ?
            ORDER BY id DESC
            LIMIT 10;";

        // query on postgresql database using the sql query
        // $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetchAll();
        // $pdo->commit();

        // check if query returned any result
        if(count($result) == 0){
            $response = [];
            response($response, 404);
        }

        // get the result from the query
        $cliente = $result[0];

        $ultimasTransacoes = [];
        foreach ($result as $transacao) {
            if ($transacao['valor'] != null) {
                $ultimasTransacoes[] = [
                    'valor' => $transacao['valor'],
                    'tipo' => $transacao['tipo'],
                    'descricao' => $transacao['descricao'],
                    'realizada_em' => $transacao['datahora']
                ];
            }
        }
        $response = [
            'saldo' => [
                'total' => $cliente['ultimosaldo'],
                'data_extrato' => $cliente['datahora_extrato'],
                'limite' => $cliente['ultimolimite']
            ],
            'ultimas_transacoes' => $ultimasTransacoes,
        ];
        response($response);

    }
    
    exit;

?>