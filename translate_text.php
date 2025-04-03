<?php
// translate_text.php - Handles AJAX translation requests

// Include the translation service
require_once 'translation_service.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if text parameter is provided
if (!isset($_GET['text']) || empty($_GET['text'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No text provided for translation'
    ]);
    exit;
}

// Get the text to translate
$text = $_GET['text'];

// Initialize translation service
$translator = new TranslationService();

// Detect language
$sourceLanguage = $translator->detectLanguage($text);

// If already English, no need to translate
if ($sourceLanguage == 'en') {
    echo json_encode([
        'success' => true,
        'translation' => $text,
        'source_language' => 'en',
        'source_language_name' => 'English',
        'message' => 'Text is already in English'
    ]);
    exit;
}

// Translate the text
$translation = $translator->translateToEnglish($text, $sourceLanguage);

// Return the result
echo json_encode([
    'success' => true,
    'translation' => $translation,
    'source_language' => $sourceLanguage,
    'source_language_name' => $translator->getLanguageName($sourceLanguage),
    'message' => 'Translation successful'
]);