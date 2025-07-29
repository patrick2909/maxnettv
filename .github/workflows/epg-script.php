<?php
/**
 * Script de atualização de EPG usando apenas API_URL e EPG_URLS
 */

// Verificar e carregar variáveis de ambiente
if (!getenv('API_URL') || !getenv('EPG_URLS')) {
    file_put_contents('gh-actions-log.txt', 
        date('Y-m-d H:i:s') . ' - Erro: Variáveis API_URL e EPG_URLS devem ser definidas' . PHP_EOL, 
        FILE_APPEND);
    die('Erro: Variáveis API_URL e EPG_URLS devem ser definidas');
}

$api_url = getenv('API_URL');
$epg_urls = explode('|', getenv('EPG_URLS'));
$output_file = 'epg.xml'; // Nome fixo do arquivo de saída

// Função para obter os IDs de canais permitidos da API
function getPermittedChannelIds($api_url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    try {
        $response = curl_exec($ch);
        
        if ($response === false) {
            throw new Exception('Erro na API: ' . curl_error($ch));
        }
        
        $data = json_decode($response, true);
        if ($data === null || !is_array($data)) {
            throw new Exception('Resposta da API inválida');
        }
        
        $permitted_ids = [];
        foreach ($data as $item) {
            if (isset($item['id_canal'])) {
                $permitted_ids[] = (string)$item['id_canal'];
            }
        }
        
        return $permitted_ids;
    } catch (Exception $e) {
        file_put_contents('gh-actions-log.txt', 
            date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . PHP_EOL, 
            FILE_APPEND);
        die('Erro: ' . $e->getMessage());
    } finally {
        curl_close($ch);
    }
}

// Carregar e processar EPG (mesma função do original)
function loadEpg($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR => true
    ]);
    
    try {
        $content = curl_exec($ch);
        if ($content === false) {
            throw new Exception('Erro ao baixar: ' . curl_error($ch));
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = array_map(function($error) {
                return $error->message;
            }, $errors);
            throw new Exception('Erro XML: ' . implode(', ', $error_messages));
        }
        
        return $xml;
    } catch (Exception $e) {
        file_put_contents('gh-actions-log.txt', 
            date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . PHP_EOL, 
            FILE_APPEND);
        return null;
    } finally {
        curl_close($ch);
    }
}

// --- Execução principal ---

// Obter IDs permitidos
$permitted_channel_ids = getPermittedChannelIds($api_url);

// Carregar EPGs
$epgs = [];
foreach ($epg_urls as $url) {
    if ($epg = loadEpg($url)) {
        $epgs[] = $epg;
    }
}

if (empty($epgs)) {
    file_put_contents('gh-actions-log.txt', 
        date('Y-m-d H:i:s') . ' - Nenhum EPG carregado' . PHP_EOL, 
        FILE_APPEND);
    die('Erro: Nenhum EPG carregado');
}

// Criar EPG unificado
$unified_epg = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tv></tv>');

// Adicionar canais e programas (mesma lógica do original)
// [...] (manter exatamente igual ao seu código original)

// Salvar arquivo
if ($unified_epg->asXML($output_file)) {
    echo "EPG atualizado em " . date('Y-m-d H:i:s');
    file_put_contents('gh-actions-log.txt', 
        date('Y-m-d H:i:s') . ' - EPG gerado com sucesso' . PHP_EOL, 
        FILE_APPEND);
} else {
    file_put_contents('gh-actions-log.txt', 
        date('Y-m-d H:i:s') . ' - Erro ao salvar EPG' . PHP_EOL, 
        FILE_APPEND);
    die('Erro ao salvar EPG');
}
?>
