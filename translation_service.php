<?php
// translation_service.php

class TranslationService {
    private $apiKey;
    
    public function __construct($apiKey = null) {
        // Replace with your actual Google Translate API key
        $this->apiKey = $apiKey ?: 'YOUR_GOOGLE_TRANSLATE_API_KEY';
    }
    
    public function detectLanguage($text) {
        // Simple language detection without API
        // This is a basic fallback if you don't have an API key
        $text = strtolower($text);
        
        // Spanish detection (basic)
        if (preg_match('/[áéíóúñ¿¡]/', $text) || 
            strpos($text, ' el ') !== false || 
            strpos($text, ' la ') !== false || 
            strpos($text, ' los ') !== false || 
            strpos($text, ' las ') !== false || 
            strpos($text, ' que ') !== false) {
            return 'es';
        }
        
        // French detection (basic)
        if (preg_match('/[àâæçéèêëîïôœùûüÿ]/', $text) || 
            strpos($text, ' le ') !== false || 
            strpos($text, ' la ') !== false || 
            strpos($text, ' les ') !== false || 
            strpos($text, ' des ') !== false || 
            strpos($text, ' est ') !== false) {
            return 'fr';
        }
        
        // Add more language detection as needed
        
        // Default to English
        return 'en';
    }
    
    public function translateToEnglish($text, $sourceLanguage = null) {
        if (!$sourceLanguage) {
            $sourceLanguage = $this->detectLanguage($text);
        }
        
        if ($sourceLanguage == 'en') {
            return $text; // Already English
        }
        
        // If you have an API key, use it for translation
        if ($this->apiKey && $this->apiKey != 'YOUR_GOOGLE_TRANSLATE_API_KEY') {
            return $this->translateWithApi($text, $sourceLanguage, 'en');
        }
        
        // Without API, we'll use a fallback method
        return $this->fallbackTranslate($text, $sourceLanguage);
    }
    
    private function translateWithApi($text, $source, $target) {
        $url = 'https://translation.googleapis.com/language/translate/v2';
        $data = [
            'q' => $text,
            'source' => $source,
            'target' => $target,
            'format' => 'text',
            'key' => $this->apiKey
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Translation API error: $error");
            return $text; // Return original on error
        }
        
        $result = json_decode($response, true);
        if (isset($result['data']['translations'][0]['translatedText'])) {
            return $result['data']['translations'][0]['translatedText'];
        }
        
        return $text; // Return original if translation fails
    }
    
    private function fallbackTranslate($text, $sourceLanguage) {
        // Very basic translation for demo purposes
        // In production, you should always use a proper translation API
        
        // Sample translations for common Spanish words
        if ($sourceLanguage == 'es') {
            $translations = [
                'hola' => 'hello',
                'buscar' => 'search',
                'como' => 'how',
                'que' => 'what',
                'donde' => 'where',
                // Add more common words as needed
            ];
            
            foreach ($translations as $spanish => $english) {
                $text = str_ireplace($spanish, $english, $text);
            }
        }
        
        // Sample translations for common French words
        if ($sourceLanguage == 'fr') {
            $translations = [
                'bonjour' => 'hello',
                'rechercher' => 'search',
                'comment' => 'how',
                'quoi' => 'what',
                'où' => 'where',
                // Add more common words as needed
            ];
            
            foreach ($translations as $french => $english) {
                $text = str_ireplace($french, $english, $text);
            }
        }
        
        return $text;
    }
    
    public function getLanguageName($languageCode) {
        $languages = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            // Add more languages as needed
        ];
        
        return $languages[$languageCode] ?? $languageCode;
    }
}
?>