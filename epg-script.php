<?php
/**
 * Script de atualização de EPG para GitHub Actions
 * Variáveis necessárias:
 * - API_URL: URL da API para listar canais permitidos
 * - EPG_URLS: Lista de URLs de EPGs separadas por |
 */

// Configuração inicial
error_reporting(E_ALL);
ini_set('display_errors', 1);
$start_time = microtime(true);

// Verificar variáveis de ambiente
if (!getenv('API_URL') || !getenv('EPG_URLS')) {
    log_error("Variáveis de ambiente API_URL e EPG_URLS são obrigatórias");
    die("Erro: Configuração incompleta");
}

$config = [
    'api_url' => getenv('API_URL'),
    'epg_urls' => explode('|', getenv('EPG_URLS')),
    'output_file' => 'epg.xml',
    'timeout' => 30
];

// Função para log de erros
function log_error($message) {
    $log_msg = date('[Y-m-d H:i:s]') . ' ERRO: ' . $message . PHP_EOL;
    file_put_contents('epg-updater.log', $log_msg, FILE_APPEND);
    echo $log_msg;
}

// Função para fazer requisições HTTP
function fetch_url($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'EPG-Updater/1.0'
    ]);
    
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("CURL Error: " . curl_error($ch));
    }
    curl_close($ch);
    return $response;
}

// Obter IDs de canais permitidos
try {
    $permitted_ids = [];
    $api_data = json_decode(fetch_url($config['api_url']), true);
    
    if (!empty($api_data)) {
        foreach ($api_data as $item) {
            if (!empty($item['id_canal'])) {
                $permitted_ids[] = (string)$item['id_canal'];
            }
        }
    }
} catch (Exception $e) {
    log_error("Falha ao obter canais permitidos: " . $e->getMessage());
    die("Erro: " . $e->getMessage());
}

// Processar EPGs
$epg_data = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tv></tv>');
$processed_channels = [];

foreach ($config['epg_urls'] as $epg_url) {
    try {
        $xml = simplexml_load_string(fetch_url($epg_url));
        if ($xml === false) {
            throw new Exception("XML inválido");
        }

        // Processar canais
        foreach ($xml->channel as $channel) {
            $channel_id = (string)$channel['id'];
            if (in_array($channel_id, $permitted_ids) && !isset($processed_channels[$channel_id])) {
                $new_channel = $epg_data->addChild('channel');
                $new_channel->addAttribute('id', $channel_id);
                
                foreach ($channel->children() as $child) {
                    $new_child = $new_channel->addChild($child->getName(), htmlspecialchars((string)$child));
                    foreach ($child->attributes() as $attr => $value) {
                        $new_child->addAttribute($attr, (string)$value);
                    }
                }
                
                $processed_channels[$channel_id] = true;
            }
        }

        // Processar programas
        foreach ($xml->programme as $program) {
            $channel_id = (string)$program['channel'];
            if (in_array($channel_id, $permitted_ids)) {
                $new_program = $epg_data->addChild('programme');
                foreach ($program->attributes() as $attr => $value) {
                    $new_program->addAttribute($attr, (string)$value);
                }
                
                foreach ($program->children() as $child) {
                    $new_child = $new_program->addChild($child->getName(), htmlspecialchars((string)$child));
                    foreach ($child->attributes() as $attr => $value) {
                        $new_child->addAttribute($attr, (string)$value);
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        log_error("Erro ao processar EPG ($epg_url): " . $e->getMessage());
    }
}

// Adicionar metadados
$epg_data->addChild('generated_at', date('Y-m-d H:i:s'));
$epg_data->addChild('execution_time', round(microtime(true) - $start_time, 2) . 's');

// Salvar arquivo
if ($epg_data->asXML($config['output_file'])) {
    echo "EPG gerado com sucesso em " . $config['output_file'] . "\n";
    echo "Canais incluídos: " . count($processed_channels) . "\n";
} else {
    log_error("Falha ao salvar arquivo EPG");
    die("Erro ao salvar EPG");
}
?>
