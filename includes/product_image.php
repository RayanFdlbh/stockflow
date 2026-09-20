
<?php

/**
 * Enregistre une image de produit.
 *
 * Retourne :
 * - null si aucune image n'a été envoyée ;
 * - le nom du fichier si l'importation réussit.
 *
 * Lance une RuntimeException si le fichier est invalide.
 */
function uploadProductImage(string $fieldName = 'image'): ?string
{
    if (
        !isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if (
        !isset(
            $file['error'],
            $file['size'],
            $file['tmp_name']
        ) ||
        !is_int($file['error']) ||
        !is_int($file['size']) ||
        !is_string($file['tmp_name']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            "L'importation de l'image a échoué."
        );
    }

    // Limite : 5 Mo
    $maxSize = 5 * 1024 * 1024;

    if ($file['size'] <= 0 || $file['size'] > $maxSize) {
        throw new RuntimeException(
            "L'image doit peser moins de 5 Mo."
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(
            "Le fichier envoyé est invalide."
        );
    }

    // Vérification du type réel du fichier
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException(
            'Format non autorisé. Utilisez JPG, PNG ou WebP.'
        );
    }

    // Vérification complémentaire du contenu de l'image
    if (@getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException(
            "Le contenu de l'image est invalide."
        );
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/products/';

    if (!is_dir($uploadDirectory)) {
        throw new RuntimeException(
            "Le dossier d'importation des images est introuvable."
        );
    }

    // Nom imprévisible, sans utiliser le nom fourni par l'utilisateur
    $extension = $allowedTypes[$mimeType];
    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

    $destination = $uploadDirectory . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException(
            "Impossible d'enregistrer l'image sur le serveur."
        );
    }

    return $fileName;
}

/**
 * Supprime une image précédemment enregistrée.
 * Ne prend en charge que les noms générés par StockFlow.
 */
function deleteProductImage(?string $fileName): void
{
    if (
        $fileName === null ||
        !preg_match(
            '/^[a-f0-9]{32}\.(jpg|png|webp)$/D',
            $fileName
        )
    ) {
        return;
    }

    $path = dirname(__DIR__) . '/uploads/products/' . $fileName;

    if (is_file($path)) {
        unlink($path);
    }
}
