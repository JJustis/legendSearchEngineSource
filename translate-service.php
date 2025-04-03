<?php
/**
 * Language Translation Service
 * 
 * This file handles language detection and translation services for the search engine
 */

class TranslationService {
    // Default languages supported (can be expanded)
    private $supportedLanguages = [
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
        'hi' => 'Hindi'
    ];
    
    // Store the detected language and translations
    private $detectedLanguage = 'en';
    private $originalQuery = '';
    private $translatedQuery = '';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize any needed configuration
    }
    
    /**
     * Get the list of supported languages
     * 
     * @return array Array of supported languages
     */
    public function getSupportedLanguages() {
        return $this->supportedLanguages;
    }
    
    /**
     * Detect the language of a given text
     * 
     * @param string $text The text to detect language for
     * @return string Language code
     */
    public function detectLanguage($text) {
        // In a production environment, this would use an external API
        // like Google Cloud Translation API, Microsoft Translator, etc.
        
        // For demonstration purposes, we'll use a simple detection method
        // based on character sets and common words
        
        $text = trim($text);
        
        if (empty($text)) {
            return 'en'; // Default to English for empty text
        }
        
        // Store original query
        $this->originalQuery = $text;
        
        // Check for specific language patterns
        
        // Chinese characters
        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $text)) {
            $this->detectedLanguage = 'zh';
            return 'zh';
        }
        
        // Japanese characters (Hiragana and Katakana)
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) {
            $this->detectedLanguage = 'ja';
            return 'ja';
        }
        
        // Korean characters
        if (preg_match('/[\x{AC00}-\x{D7A3}]/u', $text)) {
            $this->detectedLanguage = 'ko';
            return 'ko';
        }
        
        // Russian (Cyrillic)
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            $this->detectedLanguage = 'ru';
            return 'ru';
        }
        
        // Arabic
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            $this->detectedLanguage = 'ar';
            return 'ar';
        }
        
        // Check for common words in different languages
        $spanishWords = ['de', 'la', 'el', 'en', 'y', 'es', 'por', 'un', 'una', 'que'];
        $frenchWords = ['le', 'la', 'les', 'un', 'une', 'des', 'et', 'est', 'sont', 'avec'];
        $germanWords = ['der', 'die', 'das', 'ein', 'eine', 'und', 'ist', 'für', 'von', 'mit'];
        $italianWords = ['il', 'la', 'i', 'gli', 'e', 'è', 'un', 'una', 'per', 'con'];
        $portugueseWords = ['o', 'a', 'os', 'as', 'um', 'uma', 'e', 'é', 'para', 'com'];
        
        // Convert to lowercase and split into words
        $words = preg_split('/\s+/', strtolower($text));
        
        // Count matches for each language
        $scores = [
            'es' => 0,
            'fr' => 0,
            'de' => 0,
            'it' => 0,
            'pt' => 0,
            'en' => 0  // Default score for English
        ];
        
        foreach ($words as $word) {
            if (in_array($word, $spanishWords)) $scores['es']++;
            if (in_array($word, $frenchWords)) $scores['fr']++;
            if (in_array($word, $germanWords)) $scores['de']++;
            if (in_array($word, $italianWords)) $scores['it']++;
            if (in_array($word, $portugueseWords)) $scores['pt']++;
        }
        
        // Add basic English detection
        if (preg_match('/^[a-zA-Z0-9\s\.\,\?\!\-\_\'\"\:\;]+$/', $text)) {
            $scores['en'] += 2; // Bias slightly toward English for Latin alphabet
        }
        
        // Find the language with the highest score
        $maxScore = 0;
        $detectedLang = 'en'; // Default to English
        
        foreach ($scores as $lang => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $detectedLang = $lang;
            }
        }
        
        $this->detectedLanguage = $detectedLang;
        return $detectedLang;
    }
    
    /**
     * Translate text from detected language to English
     * 
     * @param string $text Text to translate
     * @param string $fromLang Source language (if known)
     * @return string Translated text
     */
    public function translateToEnglish($text, $fromLang = null) {
        if (empty($text)) {
            return '';
        }
        
        // Store original query
        $this->originalQuery = $text;
        
        // Detect language if not provided
        if ($fromLang === null) {
            $fromLang = $this->detectLanguage($text);
        }
        
        // If already English, return as is
        if ($fromLang === 'en') {
            $this->translatedQuery = $text;
            return $text;
        }
        
        // In a production environment, this would use an external API
        // like Google Cloud Translation API, Microsoft Translator, etc.
        
        // For demonstration, we'll use a simple dictionary-based translation
        // This is just for demonstration and won't handle complex translations
        
        $translations = [
            'es' => [
                'hola' => 'hello',
                'buscar' => 'search',
                'cómo' => 'how',
                'qué' => 'what',
                'dónde' => 'where',
                'quién' => 'who',
                'por qué' => 'why',
                'cuando' => 'when',
                'imagen' => 'image',
                'video' => 'video',
                'noticias' => 'news',
                'información' => 'information'
            ],
            'fr' => [
                'bonjour' => 'hello',
                'rechercher' => 'search',
                'comment' => 'how',
                'quoi' => 'what',
                'où' => 'where',
                'qui' => 'who',
                'pourquoi' => 'why',
                'quand' => 'when',
                'image' => 'image',
                'vidéo' => 'video',
                'nouvelles' => 'news',
                'information' => 'information'
            ],
            'de' => [
                'hallo' => 'hello',
                'suchen' => 'search',
                'wie' => 'how',
                'was' => 'what',
                'wo' => 'where',
                'wer' => 'who',
                'warum' => 'why',
                'wann' => 'when',
                'bild' => 'image',
                'video' => 'video',
                'nachrichten' => 'news',
                'information' => 'information'
            ],
            // Add more languages and translations as needed
        ];
        
        // If we have translations for this language
        if (isset($translations[$fromLang])) {
            $words = preg_split('/\s+/', strtolower($text));
            $translatedWords = [];
            
            foreach ($words as $word) {
                if (isset($translations[$fromLang][$word])) {
                    $translatedWords[] = $translations[$fromLang][$word];
                } else {
                    // Keep original word if no translation
                    $translatedWords[] = $word;
                }
            }
            
            $translatedText = implode(' ', $translatedWords);
            $this->translatedQuery = $translatedText;
            return $translatedText;
        }
        
        // If no translation available, return original text
        // In a real application, this would call an external API
        $this->translatedQuery = $text;
        return $text;
    }
    
    /**
     * Translate text from English to another language
     * 
     * @param string $text Text to translate
     * @param string $toLang Target language
     * @return string Translated text
     */
    public function translateFromEnglish($text, $toLang) {
        if (empty($text) || $toLang === 'en') {
            return $text;
        }
        
        // In a production environment, this would use an external API
        // For demonstration, we'll just return the original text
        
        return $text;
    }
    
    /**
     * Get detected language name
     * 
     * @return string Language name
     */
    public function getDetectedLanguageName() {
        return isset($this->supportedLanguages[$this->detectedLanguage]) 
            ? $this->supportedLanguages[$this->detectedLanguage] 
            : 'Unknown';
    }
    
    /**
     * Get detected language code
     * 
     * @return string Language code
     */
    public function getDetectedLanguageCode() {
        return $this->detectedLanguage;
    }
    
    /**
     * Get original query
     * 
     * @return string Original query
     */
    public function getOriginalQuery() {
        return $this->originalQuery;
    }
    
    /**
     * Get translated query
     * 
     * @return string Translated query
     */
    public function getTranslatedQuery() {
        return $this->translatedQuery;
    }
    
    /**
     * Translate search results back to original language
     * 
     * @param array $results Search results to translate
     * @param string $toLang Target language
     * @return array Translated results
     */
    public function translateResults($results, $toLang) {
        if (empty($results) || $toLang === 'en') {
            return $results;
        }
        
        // In a production environment, this would use an external API
        // For demonstration, we'll just return the original results
        // with a "Translated from English" notice
        
        foreach ($results as &$result) {
            if (isset($result['title'])) {
                $result['original_title'] = $result['title'];
                // In real implementation, this would be translated
            }
            
            if (isset($result['description'])) {
                $result['original_description'] = $result['description'];
                // In real implementation, this would be translated
            }
            
            // Add translation notice
            $result['translated'] = true;
            $result['source_language'] = 'en';
            $result['target_language'] = $toLang;
        }
        
        return $results;
    }
}
