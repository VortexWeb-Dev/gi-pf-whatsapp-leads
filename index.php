<?php

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/crest/crest.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

function mb($apiKey, $payload)
{
    return hash_hmac('sha256', $payload, $apiKey);
}

$apiKey = '000248b79731f8d3418df9db1ee3e657';
$signature = getallheaders()['X-Propertyfinder-Signature'] ?? '';
$payload = file_get_contents('php://input');

logData("Received raw payload: " . $payload, "webhook.log");

$computedSignature = mb($apiKey, $payload);

// if (!hash_equals($computedSignature, $signature)) {
//     logData("Invalid signature. Computed: $computedSignature, Provided: $signature", "webhook.log");
//     respondWithError(400, "Invalid signature. Please check API key.");
// }

if (empty($payload)) {
    logData("Empty payload received", "webhook.log");
    respondWithError(400, "No payload received");
}

$data = json_decode($payload, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logData("Invalid JSON payload: " . $payload, "webhook.log");
    respondWithError(400, "Invalid JSON");
}
// Extract attributes
$attributes = $data['data']['attributes'] ?? [];
$phone = $attributes['enquirer_phone_number'] ?? null;
$message = $attributes['message'] ?? null;
$tracking_link = $attributes['tracking_link'] ?? null;
$received_at = isset($attributes['received_at']) ? date('Y-m-d H:i:s', strtotime($attributes['received_at'])) : null;

// Extract agent details
$agent_data = $data['data']['relationships']['agents']['data'] ?? [];
$agent_email = $agent_data['attributes']['email'] ?? null;
$agent_name = $agent_data['attributes']['full_name'] ?? null;
$agent_number = $agent_data['attributes']['whatsapp_phone_number'] ?? null;

// Extract property details
$property_data = $data['data']['relationships']['properties']['data'] ?? [];
$category = $property_data['attributes']['category'] ?? null;
$prices = $property_data['attributes']['prices'] ?? [];
$price_type = null; // Initialize price type

// Check for price types
if (!empty($prices)) {
    foreach (['yearly', 'monthly', 'weekly', 'daily'] as $type) {
        if (isset($prices[$type])) {
            $price = $prices[$type];
            $price_type = ucfirst($type); // Capitalize the type (e.g., "Yearly", "Monthly")
            break;
        }
    }
}

$reference = $property_data['attributes']['reference'] ?? null;
$listing_link = $property_data['attributes']['website_link'] ?? null;

// Extract locations
$locations = $property_data['relationships']['locations']['data'] ?? [];
$location_names = array_map(function ($location) {
    return $location['attributes']['name'] ?? null;
}, $locations);
$location_names = array_filter($location_names); // Remove null values

// Log extracted data
$extracted_data = [
    'phone' => $phone,
    'message' => $message,
    'tracking_link' => $tracking_link,
    'received_at' => $received_at,
    'agent_email' => $agent_email,
    'agent_name' => $agent_name,
    'agent_number' => $agent_number,
    'category' => $category,
    'price' => isset($price) ? "{$price} ({$price_type})" : null,
    'reference' => $reference,
    'listing_link' => $listing_link,
    'locations' => implode(' - ', $location_names),
];
logData("Extracted data: " . json_encode($extracted_data, JSON_PRETTY_PRINT), "extracted.log");

$comments = "Property Details:\n";
$comments .= "Reference: $reference\n";
$comments .= "Price: " . (isset($price) ? "$price ($price_type)" : '') . "\n";
$comments .= "Category: $category\n";
$comments .= "Link: $listing_link\n";
$comments .= "Tracking Link: $tracking_link\n";
$comments .= "Location: " . implode(' - ', $location_names) . "\n";
$comments .= "\nClient Details:\n";
$comments .= "WhatsApp Number: $phone\n\n";
$comments .= "Message: $message\n";
$comments .= "\nLead from Property Finder";

$fields = [
    'TITLE' => "Property Finder - WhatsApp - $reference",
    'CATEGORY_ID' => 24,
    'SOURCE_ID' => 'CALLBACK',
    'UF_CRM_62A5B8743F62A' => $phone,
    'UF_CRM_1739890146108' => $reference,
    'UF_CRM_1739945676' => $listing_link,
    'COMMENTS' => $comments,
    'UF_CRM_1739873044322' => $tracking_link,
];

// Get listing owner
$owner_id = getResponsiblePerson($reference, 'reference');
$fields['ASSIGNED_BY_ID'] = $owner_id;

$new_lead_id = createBitrixLead($fields);
$fields['leadId'] = $new_lead_id;
logData("Fields: " . json_encode($fields, JSON_PRETTY_PRINT), "fields.log");


if ($new_lead_id) {
    logData("New lead created: " . $new_lead_id, "webhook.log");
    respondWithSuccess("Webhook processed");
}
