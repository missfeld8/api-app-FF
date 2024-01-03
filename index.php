<?php

class Router
{
    private $routes = [];

    public function get($path, $callback)
    {
        $this->addRoute('GET', $path, $callback);
    }

    public function post($path, $callback)
    {
        $this->addRoute('POST', $path, $callback);
    }

    private function addRoute($method, $path, $callback)
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
        ];
    }

    public function resolve($request, $response)
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->isPathMatch($route['path'], $path)) {
                $params = $this->extractParams($route['path'], $path);
                call_user_func($route['callback'], $request, $response, ...$params);
                return;
            }
        }

        http_response_code(404);
        echo "Not Found";
        exit;
    }

    private function isPathMatch($pattern, $path)
    {
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '/^' . $pattern . '$/';
        return (bool) preg_match($pattern, $path);
    }

    private function extractParams($pattern, $path)
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        $params = [];
        foreach ($patternParts as $index => $part) {
            if (isset($pathParts[$index]) && !empty($part) && $part[0] === '{' && substr($part, -1) === '}') {
                $params[] = $pathParts[$index];
            }
        }

        return $params;
    }
}

$databaseConfig = [
    'host' => 'localhost',
    'user' => 'mateus',
    'password' => 'Mm@#91284025',
    'database' => 'articlesTable',
];

try {
    $db = new PDO(
        "mysql:host={$databaseConfig['host']};dbname={$databaseConfig['database']}",
        $databaseConfig['user'],
        $databaseConfig['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Erro no banco de dados: " . $e->getMessage());
    return "Erro no banco de dados: " . $e->getMessage();
} catch (Exception $e) {
    error_log("Erro: " . $e->getMessage());
    return "Server Error: " . $e->getMessage();
}

$router = new Router();

$router->get('/get', function ($request, $response) use ($db) {
    try {
        $response->header('Content-Type', 'application/json');

        if (!$db) {
            throw new PDOException("Falha na conexão com o banco de dados.");
        }

        $query = $db->query("SELECT * FROM articles");

        if ($query === false) {
            throw new PDOException("Erro na execução da consulta SQL.");
        }

        $result = $query->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 200, 'data' => $result]);
        exit;
    } catch (PDOException $e) {
        $response->status(500);
        echo json_encode(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
        exit;
    }
});

$router->get('/find/{id}', function ($request, $response) use ($db) {
    try {
        $id = end($request->getParams());

        if ($id !== null) {
            $query = $db->prepare("SELECT * FROM articles WHERE id = ?");
            $query->execute([$id]);
            $result = $query->fetch(PDO::FETCH_ASSOC);

            if ($result !== false) {
                $response->status(200);
                echo json_encode(['status' => 200, 'data' => $result]);
            } else {
                $response->status(404);
                echo json_encode(['status' => 404, 'message' => 'Registro não encontrado']);
            }
        } else {
            $response->status(400);
            echo json_encode(['status' => 400, 'message' => 'Parâmetro {id} ausente ou inválido na URL']);
        }
    } catch (PDOException $e) {
        $response->status(500);
        echo json_encode(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    } finally {
        exit;
    }
});


$router->post('/create', function ($request, $response) use ($db) {
    try {
        $data = $request->post;

        // Verifica se os valores são válidos
        $requiredFields = ['name', 'article_body', 'author', 'author_avatar'];
        if (array_diff($requiredFields, array_keys($data)) === []) {
            if (array_filter($data) === $data) {
                $insertQuery = $db->prepare("INSERT INTO articles (name, article_body, author, author_avatar) VALUES (?, ?, ?, ?)");
                $insertQuery->execute([$data['name'], $data['article_body'], $data['author'], $data['author_avatar']]);

                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->write(json_encode(['status' => 201, 'message' => 'Registro criado com sucesso']));
            } else {
                $response->status(400); // Bad Request
                $response->write(json_encode(['status' => 400, 'message' => 'Os valores não podem estar vazios']));
            }
        } else {
            $response->status(400); // Bad Request
            $response->write(json_encode(['status' => 400, 'message' => 'Parâmetros inválidos']));
        }
    } catch (PDOException $e) {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->write(json_encode(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]));
    } finally {
        $response->end();
    }
});

$router->post('/update/{id}', function ($request, $response, $id) use ($db) {
    try {
        $data = json_decode($request->rawContent(), true);

        // Verifica se a decodificação foi bem-sucedida
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro na decodificação JSON: ' . json_last_error_msg());
        }

        // Verifica se os dados são válidos
        $requiredFields = ['name', 'article_body', 'author', 'author_avatar'];
        if (array_diff($requiredFields, array_keys($data)) === []) {
            // Verifica se o registro existe antes de atualizar
            $checkExistenceQuery = $db->prepare("SELECT id FROM articles WHERE id = ?");
            $checkExistenceQuery->execute([$id]);
            $existingRecord = $checkExistenceQuery->fetch(PDO::FETCH_ASSOC);

            if ($existingRecord) {
                $updateQuery = $db->prepare("UPDATE articles SET name = ?, article_body = ?, author = ?, author_avatar = ? WHERE id = ?");
                $updateQuery->execute([$data['name'], $data['article_body'], $data['author'], $data['author_avatar'], $id]);

                $response->header('Content-Type', 'application/json');
                $response->write(json_encode(['status' => 200, 'message' => 'Registro atualizado com sucesso']));
            } else {
                $response->status(404); // Not Found
                $response->write(json_encode(['status' => 404, 'message' => 'Registro não encontrado']));
            }
        } else {
            $response->status(400); // Bad Request
            $response->write(json_encode(['status' => 400, 'message' => 'Parâmetros inválidos']));
        }
    } catch (PDOException $e) {
        $response->status(500);
        $response->json(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    } catch (Exception $e) {
        $response->status(400); // Bad Request
        $response->json(['status' => 400, 'message' => 'Erro na solicitação: ' . $e->getMessage()]);
    } finally {
        $response->end();
    }
});

$router->post('/delete/{id}', function ($request, $response, $id) use ($db) {
    try {
        $checkExistenceQuery = $db->prepare("SELECT id FROM articles WHERE id = ?");
        $checkExistenceQuery->execute([$id]);
        $existingRecord = $checkExistenceQuery->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord) {
            $deleteQuery = $db->prepare("DELETE FROM articles WHERE id = ?");
            $deleteQuery->execute([$id]);

            $response->header('Content-Type', 'application/json');
            $response->write(json_encode(['status' => 200, 'message' => 'Registro excluído com sucesso']));
        } else {
            $response->status(404); // erro
            $response->header('Content-Type', 'application/json');
            $response->write(json_encode(['status' => 404, 'message' => 'Registro não encontrado']));
        }
    } catch (PDOException $e) {
        $response->status(500);
        $response->json(['status' => 500, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    } finally {
        $response->end();
    }
});

// Configuração de cabeçalho CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

// Manipulação da requisição
$router->resolve($_SERVER['REQUEST_URI'], new stdClass());