<?php

include_once("class.record.php");
include_once("class.inline.edit.php");
include_once("class.profile.php");
include_once("class.db.php");

  // Function to get field value if it exists, otherwise return an empty string
  function getFieldIfExists($array, $fieldName) {
    return isset($array[$fieldName]) ? $array[$fieldName] : '';
}

function convertFreshnessToYearsAndMonths($freshness) {
    // Assuming $freshness is in days, convert it to years and months
    $years = floor($freshness / 365);
    $remainingDays = $freshness % 365;
    $months = floor($remainingDays / 30);
    // Construct the years and months string
    $formattedFreshness = '';
    if ($years > 0) {
        $formattedFreshness .= $years . ' year';
        if ($years > 1) {
            $formattedFreshness .= 's';
        }
    }
    if ($months > 0) {
        if ($formattedFreshness !== '') {
            $formattedFreshness .= ' ';
        }
        $formattedFreshness .= $months . ' month';
        if ($months > 1) {
            $formattedFreshness .= 's';
        }
    }
    return $formattedFreshness;
}

// Function to calculate the distance between two sets of coordinates
function getDistance($coordinates1, $coordinates2) {
    $lat1 = $coordinates1['lat'];
    $lon1 = $coordinates1['lon'];
    $lat2 = $coordinates2['lat'];
    $lon2 = $coordinates2['lon'];

    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;

    return $miles;
}

$api_key = '46ImRiOJ96pfO-9jEzgGz3nGdeeAlyi5oIUrtWDh24CzCTvmgVmozQ';
$cohere_api_key = 'n1ytpDT5S9jVqY1abqvqoD6flMgo8M25UJce9fLy';

// Initialize the database class
$DB = new database();
$DB->connect();

$RECORD = new Record($DB);
$IEDIT = new inlineEdit($DB);
$SEARCH = new Searching($DB, $RECORD);
$PROFILE = new Profile($DB, $IEDIT);

$PERSON_ID = $pageParamaters['params'][0];
// Fetch dense and sparse vectors from API
$ch = curl_init($fetch_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);
$fetch_response = json_decode(curl_exec($ch), true);
curl_close($ch);

// Extract dense and sparse vectors from the response
$dense_vector = $fetch_response['result']['vector']['text-dense'];
$sparse_vector_indices = $fetch_response['result']['vector']['text-sparse']['indices'];
$sparse_vector_values = $fetch_response['result']['vector']['text-sparse']['values'];

// Construct the sparse vector in the required format
$sparse_vector = [
    'indices' => $sparse_vector_indices,
    'values' => $sparse_vector_values
];

$coordinates = [];
$distances = [];

if (isset($fetch_response['result']['vector'])) {
    $vector = $fetch_response['result']['vector'];
    $candidate_coordinates = $fetch_response['result']['payload']['location']; // Fetch the candidate's coordinates

    // Fetch the candidate's bio, nuance chunk, and psych eval from the API response
    $candidate_bio = $fetch_response['result']['payload']['bio'];
    $candidate_nuance_chunk = $fetch_response['result']['payload']['nuance_chunk'];
    $candidate_psych_eval = $fetch_response['result']['payload']['psych_eval'];

    // Create the candidate chunk by concatenating the bio, nuance chunk, and psych eval
    $candidate_chunk = $candidate_bio . "\n" . $candidate_nuance_chunk . "\n" . $candidate_psych_eval;

    // Perform similarity search in the opposite gender's collection
    $ch = curl_init($search_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'vector' => [
            'vector' => $dense_vector,
            'name' => 'text-dense'
        ],

        'limit' => 30,
        'with_payload' => true,
        'with_vector' => false,
        'score_threshold' => 0.5
    ]));
    $query_response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($query_response['result']) && count($query_response['result']) > 0) {
        $coordinates = [];

        foreach ($query_response['result'] as $match) {
            $payload = $match['payload'];
            $prospect_id = $payload['profile_id']; # note the change to $prospect_id
            $city = $payload['city'];
            $prospect_bio = $payload['bio'];
            $prospect_nuance_chunk = $payload['nuance_chunk'];
            $prospect_psych_eval = $payload['psych_eval'];
            $coordinates[$prospect_id] = [
                'city' => $city,
                'score' => $match['score'],
                'location' => $payload['location']
            ];
            $prospect_chunk = $prospect_bio . "\n" . $prospect_nuance_chunk . "\n" . $prospect_psych_eval;

            // Store the prospect chunk in the $coordinates array
            $coordinates[$prospect_id]['chunk'] = $prospect_chunk;
        }

        // Create an array to store the documents for reranking
        $documents = [];

        foreach ($query_response['result'] as $match) {
            $payload = $match['payload'];
            $prospect_id = $payload['profile_id'];
            $city = $coordinates[$prospect_id]['city'];
            $summary = $coordinates[$prospect_id]['chunk'];

            // Add the document data as a JSON-encoded string to the documents array
            $documents[] = json_encode([
                'profile_id' => $prospect_id,
                'city' => $city,
                'summary' => $summary
            ]);
        }
    
        // Rerank the documents using the Cohere Reranker API
        $ch = curl_init('https://api.cohere.ai/v1/rerank');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cohere_api_key
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'rerank-english-v2.0',
            'query' => $candidate_chunk,
            'documents' => $documents,
            'top_n' => 20,
            'return_documents' => true
        ]));
        $rerank_response = json_decode(curl_exec($ch), true);
        curl_close($ch);
    
        // Extract the reranked documents
        $reranked_documents = $rerank_response['results'];
    
        // Clear the distances array
        $distances = [];
    
        // Update the distances based on the reranked documents
        foreach ($reranked_documents as $reranked_document) {
            $document_data = json_decode($reranked_document['document']['text'], true);
            $profile_id = $document_data['profile_id'];
    
            // Find the corresponding data in the $coordinates array
            if (isset($coordinates[$profile_id])) {
                $distances[$profile_id] = getDistance($candidate_coordinates, $coordinates[$profile_id]['location']);
            }
        }
    }

        // Sort the distances in descending order
        arsort($distances);

        // Select the first 10 elements (the farthest profiles)
        $farthest_matches = array_slice($distances, 0, 10, true);
        // Reverse the order of the array so the absolute farthest is at the bottom
        $farthest_matches = array_reverse($farthest_matches, true);
        // Now $farthest_matches contains the profile IDs of the 10 farthest matches

        foreach ($query_response['result'] as $match) {
            $payload = $match['payload'];
            $profile_id = $payload['profile_id'];

            // If the profile ID is in $farthest_matches, skip to the next iteration
            if (array_key_exists($profile_id, $farthest_matches)) {
                continue;
            }
        }
    } else {
    echo "<p>Vector not found for person ID \"" . htmlspecialchars($PERSON_ID) . "\"</p>";
}


// Sort the distances array in ascending order while maintaining index association
asort($distances);

// Get the top 10 closest matches
$top_10_matches = array_slice($distances, 0, 10, true);


echo '<style>
        table {
            border-collapse: collapse;
            border: 1px solid #ddd;
            margin: auto;
            font-family: Arial, sans-serif;
            font-size: 14px;
            width: 100%;
            max-width: 1000px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
        }
        html {
            margin: 0;
            padding: 0;
            /* This resets the margin and padding for the whole page. Adjust if needed. */
        }
        body {
            font-family: \'Poppins\', sans-serif;
            background-color: #ffe1e1;
        }
        th, td {
            text-align: left;
            vertical-align: top;
            padding: 15px !important;
        }
        th {
            background-color: ' . ($match_sex == 'female' ? '#fb4444' : '#0077c2') . ';
            color: white;
        }
        tr:nth-child(even) {
            background-color: #e6f7ff;
        }
        tr:nth-child(odd) {
            background-color: #f2f2f2;
        }
        td {
            vertical-align: top;
        }
        .results {
            display: flex;
            flex-wrap: wrap;
            justify-content: center; /* Centers children horizontally */
            align-items: center; /* Attempts to center children vertically, works best if .results has a defined height */
            width: 100%;
            min-height: 100vh; /* This will make .results at least the height of the viewport */
            margin: 0 auto; /* Centers the .results block itself horizontally */
            padding: 25px;
        }
        .results_less {
            display: flex;
            flex-wrap: wrap;
            justify-content: center; /* Centers children horizontally */
            align-items: center; /* Attempts to center children vertically, works best if .results has a defined height */
            width: 100%;
            min-height: 100vh; /* This will make .results at least the height of the viewport */
            margin: 0 auto; /* Centers the .results block itself horizontally */
            padding: 25px;
            background-color: #dddddd;
        }
        .matches {
            width: 90%; /* Adjust width as needed for your design */
            margin: 20px auto; /* Keeps the top and bottom margin, auto centers horizontally */
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        td {
            background-color: #fff;
        }
        .extra-bold {
            font-weight: 700; 
        }		

        @media (max-width: 900px) {
            .results {
                flex-direction: column;
                align-items: center;
            }
        }

        .m-d.expand-list{
            margin: 0;
            padding: 0;
        }
        .m-d.expand-list > li{
            list-style-type: none;
            padding: 15px 0;
            border-bottom: 1px solid #212121;
            position: relative;

        }
        .m-d label[class^="tab"]:hover{
            cursor: pointer;
        }
        .m-d input{
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .m-d input[class^="tab"]{
            width: 100%;
            height: 40px;
            position: absolute;
            left: 0;
            top: 0; 
        }
            .m-d input[class^="tab"]:hover{
                cursor: pointer;
            }
        .m-d label[class^="tab"]{
            font-weight: bold;
        }
        .m-d .content{
            height: auto;
            max-height: 0;
            max-width: 1000px;
            overflow: hidden;
            transform: translateY(20px);
            transition: all 180ms ease-in-out 0ms; 
        }
        .m-d li[data-md-content="100"] input[class^="tab"]:checked ~ .content{
            max-height: 100px;
            transition: all 150ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="200"] input[class^="tab"]:checked ~ .content{
            max-height: 200px;
            transition: all 200ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="300"] input[class^="tab"]:checked ~ .content{
            max-height: 300px;
            transition: all 250ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="400"] input[class^="tab"]:checked ~ .content{
            max-height: 400px;
            transition: all 250ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="500"] input[class^="tab"]:checked ~ .content{
            max-height: 500px;
            transition: all 250ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="600"] input[class^="tab"]:checked ~ .content{
            max-height: 600px;
            transition: all 250ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="700"] input[class^="tab"]:checked ~ .content{
            max-height: 700px;
            transition: all 300ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="800"] input[class^="tab"]:checked ~ .content{
            max-height: 800px;
            transition: all 300ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="900"] input[class^="tab"]:checked ~ .content{
            max-height: 900px;
            transition: all 300ms ease-in-out 0ms;
        }
        .m-d li[data-md-content="1000"] input[class^="tab"]:checked ~ .content{
            max-height: 1000px;
            transition: all 350ms ease-in-out 0ms;
        }
            .m-d li[data-md-content=""] input[class^="tab"]:checked ~ .content{
            max-height: 1000px;
            transition: all 250ms ease-in-out 0ms;
        }
        .m-d input[class^="tab"]:checked ~ .content{
            margin-bottom: 20px;
        }
        
        .m-d .open-close-icon{
            display: inline-block;
            position: absolute;
            right: 20px;
            transform: translatey(2px);
        }
        .m-d .open-close-icon i{
            position: absolute;
            left: 0;
        }
        .m-d .open-close-icon .fa-minus{
            transform:rotate(-90deg);
            transition: transform 150ms ease-in-out 0ms;
        }
        .m-d input[class^="tab"]:checked ~ .open-close-icon .fa-minus{
            transform: rotate(0deg);
            transition: transform 150ms ease-in-out 0ms;
        }
        .m-d .open-close-icon .fa-plus{
            opacity: 1;
            transform:rotate(-90deg);
            transition: opacity 50ms linear 0ms, transform 150ms ease-in-out 0ms;
        }
        .m-d input[class^="tab"]:checked ~ .open-close-icon .fa-plus{
            opacity: 0;
            transform: rotate(0deg);
            transition: opacity 50ms linear 0ms, transform 150ms ease-in-out 0ms; 
        }

    </style>';

// Retrieve the image path for each profile ID
echo '<h1 style="text-align:center;">Top 10 Closest ' . ucfirst($match_sex) . ' Matches for ' . htmlspecialchars($firstName) . '</h1>';
// echo "<h2>Top Closest Matches</h2>";
echo '<div class="results">';
    
// foreach ($query_response['result'] as $match) {
	foreach ($top_10_matches as $profile_id => $distance) {

//    $payload = $match['payload'];
    $match = array_filter($query_response['result'], function($item) use ($profile_id) {
        return $item['payload']['profile_id'] == $profile_id;
    });
    $match = array_shift($match);
    $payload = $match['payload'];

    $the_profile_id = getFieldIfExists($payload, 'profile_id');
    // echo "<script>alert('$the_profile_id');</script>";
    $P1_SQL = "
        SELECT
            Persons.Gender,
            PersonsImages.PersonsImages_path,
            PersonsImages.PersonsImages_status
        FROM
            Persons
            LEFT JOIN PersonsImages ON PersonsImages.Person_id = Persons.Person_id
        WHERE
            Persons.Person_id = " . $the_profile_id . "
            AND PersonsImages.PersonsImages_status = 2";
    
    $P1_DTA = $DB->get_single_result($P1_SQL);

    $image_path = $P1_DTA['PersonsImages_path'];
    // echo "<script>alert('$image_path');</script>";

    if (empty($image_path)) { // Simplified check for null or empty string
        $image_url = $default_image; // Use the default image if no specific image path is found
    } else {
        // Determine the correct image URL based on the profile ID and image path
        $selected_range = null;
        $ranges = [
            '100001-120000', '120001-140000', '140001-160000', '160001-180000',
            '180001-200000', '200001-220000', '220001-240000', '240001-260000',
        ];
    
        foreach ($ranges as $range) {
            list($start, $end) = explode('-', $range);
            if ($the_profile_id >= $start && $the_profile_id <= $end) {
                $selected_range = $range;
                break;
            }
        }
    
        if ($selected_range) {
            // Ensure $profile_id is used here if this code is part of a loop handling multiple profiles
            $image_url = "https://kiss.kelleher-international.com/client_media/" . $selected_range . "/" . $the_profile_id . "/" . $image_path;
            // echo $image_url; // For debugging purposes
        } else {
            // Fallback if no range matches
            $image_url = $default_image;
        }
    }

    // echo '<div class="matches">';
    echo '<table style="padding: 10px;">';
    echo '<tr><td>'; 

    echo '<table>';
    echo '<thead><tr>
    <th rowspan="5"><h1><a href="https://kiss-qa.kelleher-international.com/profile/' . $the_profile_id . '" style="color: white;><strong class="extra-bold">' . $payload['first_name'] . '</strong></a></h1><span class="normal-light">' . $the_profile_id . '</span></th>
    <th><h4><strong class="extra-bold">Age</strong></h4>' . getFieldIfExists($payload, 'my_age_is') . '</th>
    <th colspan="2"><h4><strong class="extra-bold">Income</strong></h4>' . getFieldIfExists($payload, 'income') . '</th>
    <th><h4><strong class="extra-bold">KI Type</strong></h4>' . getFieldIfExists($payload, 'ki-type') . '</th>
    <th colspan="2"><h4><strong class="extra-bold">City, State</strong></h4>' . getFieldIfExists($payload, 'city') . ', ' . getFieldIfExists($payload, 'state') . '</th>
    </tr></thead>';
    echo '<tbody>';

    // First row with profile photo and first set of details
    echo '<tr>';
    echo '<td rowspan="2"><a href="https://kiss-qa.kelleher-international.com/profile/' . $the_profile_id . '" target="results"><img src="' . $image_url . '" alt="' . $payload['First name'] . '\'s Profile Image" height="150px" ></a></td>';

    echo '<td colspan="2"><strong class="extra-bold">Ethnicity:</strong><br> ' . getFieldIfExists($payload, 'ethnicity') . '</td>';
    echo '<td colspan="3"><strong class="extra-bold">Religion:</strong><br> ' . getFieldIfExists($payload, 'my_religion_is') . '</td>';
    echo '<td colspan="2"><strong class="extra-bold">Freshness:<br></strong> ' . convertFreshnessToYearsAndMonths($payload['days_since_first_contact']) . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td colspan="2"><strong class="extra-bold">Have children?</strong><br> ' . getFieldIfExists($payload, 'have_children') . '</td>';
    echo '<td colspan="3"><strong class="extra-bold">Want children?</strong><br> ' . getFieldIfExists($payload, 'want_children') . '</td>';
    echo '<td colspan="2"><strong class="extra-bold">Their kids ok?<br></strong><br> ' . getFieldIfExists($payload, 'his_or_her_children_ok') . '</td>';
    echo '</tr>';

    // Politics
    echo '<tr>';
    echo '<td colspan="5"><strong class="extra-bold">Politics:</strong> ' . getFieldIfExists($payload, 'my_politics_are') . '</td>';
    echo '<td><strong class="extra-bold">Travel for a match?</strong> ' . getFieldIfExists($payload, 'willing_to_travel_for_a_match') . '</td>';
    echo '<td><strong class="extra-bold">Relocate?</strong> ' . getFieldIfExists($payload, 'open_to_relocation') . '</td>';
    echo '</tr>';

    // Rows for Travel for a Match and Relocate, directly under the photo
    echo '</td><td colspan="7" id="summary">';
    echo '<ul class="m-d expand-list">';
    echo '<li data-md-content="200">';
    echo '<label name="tab" for="tab1_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">AI BIO</label>';
    echo '<input type="checkbox" class="tab" id="tab1_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon">';
    echo '<i class="fas fa-plus"></i>';
    echo '<i class="fas fa-minus"></i>';
    echo '</span>';
    echo '<div class="content">';
    echo getFieldIfExists($payload, 'bio');
    echo '</div>';
    echo '</li>';
    echo '<li data-md-content="300">';
    echo '<label name="tab" for="tab2_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">MORE INFO</label>';
    echo '<input type="checkbox" class="tab" id="tab2_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon"><i class="fas fa-plus"></i><i class="fas fa-minus"></i></span>';
    echo '<div class="content">';
    echo "<p>" . $parsedown->text(decode_payload_chunk(getFieldIfExists($payload, 'nuance_chunk'))) . "</p>";
    echo '</div>';
    echo '</li>';
    echo '<li data-md-content="600">';
    echo '<label name="tab" for="tab3_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">DATING ANALYSIS</label>';
    echo '<input type="checkbox" checked class="tab" id="tab3_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon"><i class="fas fa-plus"></i><i class="fas fa-minus"></i></span>';
    echo '<div class="content">';
    echo "<p>" . $parsedown->text(decode_payload_chunk(getFieldIfExists($payload, 'psych_eval'))) . "</p>";
    echo '</div>';
    echo '</li>';
    echo '</ul>';
    echo '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '</td></tr>'; 
    echo '</table><br><br>';
    // echo '</div>';
    }

    echo '<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding: 10px; border: 1px solid black;">
            <h2>Original k=30 Results</h2>
            <pre style="font-size: 9px;">';
    foreach ($query_response['result'] as $match) {
        $payload = $match['payload'];
        $profile_id = $payload['profile_id'];
        $city = $payload['city'];
        $score = $match['score'];
        echo "Profile ID: $profile_id, City: $city, Score: $score\n";
    }
    echo '      </pre>
            </td>
            <td style="width: 50%; vertical-align: top; padding: 10px; border: 1px solid black;">
                <h2>Reranked k=20 Results</h2>
                <pre style="font-size: 9px;">';
    foreach ($reranked_documents as $reranked_document) {
        $document_data = json_decode($reranked_document['document']['text'], true);
        $profile_id = $document_data['profile_id'];
        $city = $document_data['city'];
        $score = $reranked_document['relevance_score'];
    echo "Profile ID: $profile_id, City: $city, Score: $score\n";
    }
    echo '      </pre>
            </td>
        </tr>
    </table>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding: 10px; border: 1px solid black;">
                <h2>Top 10 Matches</h2>
                <pre style="font-size: 9px;">';
    foreach ($top_10_matches as $profile_id => $distance_km) {
        $distance_miles = $distance_km * 0.621371; // Convert kilometers to miles
        echo "Profile ID = " . $profile_id . " ==> Distance = " . round($distance_miles, 2) . " miles\n";
    }
    echo '      </pre>
            </td>
            <td style="width: 50%; vertical-align: top; padding: 10px; border: 1px solid black;">
                <h2>Good but farther Matches</h2>
                <pre style="font-size: 9px;">';
    foreach ($farthest_matches as $profile_id => $distance_km) {
        $distance_miles = $distance_km * 0.621371; // Convert kilometers to miles
        echo "Profile ID = " . $profile_id . " ==> Distance = " . round($distance_miles, 2) . " miles\n";
    }
    echo '      </pre>
            </td>
        </tr>
    </table>';

    echo '</div><br><br><br>';

foreach ($farthest_matches as $profile_id => $distance) {
    $match = array_filter($query_response['result'], function($item) use ($profile_id) {
        return $item['payload']['profile_id'] == $profile_id;
    });
    $match = array_shift($match);
    $payload = $match['payload'];

    $the_profile_id = getFieldIfExists($payload, 'profile_id');
    // echo "<script>alert('$the_profile_id');</script>";
    $P1_SQL = "
        SELECT
            Persons.Gender,
            PersonsImages.PersonsImages_path,
            PersonsImages.PersonsImages_status
        FROM
            Persons
            LEFT JOIN PersonsImages ON PersonsImages.Person_id = Persons.Person_id
        WHERE
            Persons.Person_id = " . $the_profile_id . "
            AND PersonsImages.PersonsImages_status = 2";
    
    $P1_DTA = $DB->get_single_result($P1_SQL);

    $image_path = $P1_DTA['PersonsImages_path'];
    // echo "<script>alert('$image_path');</script>";

    if (empty($image_path)) { // Simplified check for null or empty string
        $image_url = $default_image; // Use the default image if no specific image path is found
    } else {
        // Determine the correct image URL based on the profile ID and image path
        $selected_range = null;
        $ranges = [
            '100001-120000', '120001-140000', '140001-160000', '160001-180000',
            '180001-200000', '200001-220000', '220001-240000', '240001-260000',
        ];
    
        foreach ($ranges as $range) {
            list($start, $end) = explode('-', $range);
            if ($the_profile_id >= $start && $the_profile_id <= $end) {
                $selected_range = $range;
                break;
            }
        }
    
        if ($selected_range) {
            // Ensure $profile_id is used here if this code is part of a loop handling multiple profiles
            $image_url = "https://kiss.kelleher-international.com/client_media/" . $selected_range . "/" . $the_profile_id . "/" . $image_path;
            // echo $image_url; // For debugging purposes
        } else {
            // Fallback if no range matches
            $image_url = $default_image;
        }
    }

    // echo '<div class="matches">';
    echo '<table style="padding: 10px;">';
    echo '<tr><td>'; 

    echo '<table>';
    echo '<thead><tr>
    <th rowspan="5"><h1><a href="https://kiss-qa.kelleher-international.com/profile/' . $the_profile_id . '" style="color: white;><strong class="extra-bold">' . $payload['first_name'] . '</strong></a></h1><span class="normal-light">' . $the_profile_id . '</span></th>
    <th><h4><strong class="extra-bold">Age</strong></h4>' . getFieldIfExists($payload, 'my_age_is') . '</th>
    <th colspan="2"><h4><strong class="extra-bold">Income</strong></h4>' . getFieldIfExists($payload, 'income') . '</th>
    <th><h4><strong class="extra-bold">KI Type</strong></h4>' . getFieldIfExists($payload, 'type') . '</th>
    <th colspan="2"><h4><strong class="extra-bold">City, State</strong></h4>' . getFieldIfExists($payload, 'city') . ', ' . getFieldIfExists($payload, 'state') . '</th>
    </tr></thead>';
    echo '<tbody>';

    // First row with profile photo and first set of details
    echo '<tr>';
    echo '<td rowspan="2"><a href="https://kiss-qa.kelleher-international.com/profile/' . $the_profile_id . '" target="results"><img src="' . $image_url . '" alt="' . $payload['First name'] . '\'s Profile Image" height="150px" ></a></td>';

    echo '<td colspan="2"><strong class="extra-bold">Ethnicity:</strong><br> ' . getFieldIfExists($payload, 'ethnicity') . '</td>';
    echo '<td colspan="3"><strong class="extra-bold">Religion:</strong><br> ' . getFieldIfExists($payload, 'my_religion_is') . '</td>';
    echo '<td colspan="2"><strong class="extra-bold">Freshness:<br></strong> ' . convertFreshnessToYearsAndMonths($payload['days_since_first_contact']) . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td colspan="2"><strong class="extra-bold">Have children?</strong><br> ' . getFieldIfExists($payload, 'have_children') . '</td>';
    echo '<td colspan="3"><strong class="extra-bold">Want children?</strong><br> ' . getFieldIfExists($payload, 'want_children') . '</td>';
    echo '<td colspan="2"><strong class="extra-bold">Their kids ok?<br></strong><br> ' . getFieldIfExists($payload, 'his_or_her_children_ok') . '</td>';
    echo '</tr>';

    // Politics
    echo '<tr>';
    echo '<td colspan="5"><strong class="extra-bold">Politics:</strong> ' . getFieldIfExists($payload, 'my_politics_are') . '</td>';
    echo '<td><strong class="extra-bold">Travel for a match?</strong> ' . getFieldIfExists($payload, 'willing_to_travel_for_a_match') . '</td>';
    echo '<td><strong class="extra-bold">Relocate?</strong> ' . getFieldIfExists($payload, 'open_to_relocation') . '</td>';
    echo '</tr>';

    // bio, nuance chunk, psych eval
    echo '</td><td colspan="7" id="summary">';
    echo '<ul class="m-d expand-list">';
    echo '<li data-md-content="200">';
    echo '<label name="tab" for="tab1_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">AI BIO</label>';
    echo '<input type="checkbox" class="tab" id="tab1_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon">';
    echo '<i class="fas fa-plus"></i>';
    echo '<i class="fas fa-minus"></i>';
    echo '</span>';
    echo '<div class="content">';
    echo getFieldIfExists($payload, 'bio');
    echo '</div>';
    echo '</li>';
    echo '<li data-md-content="300">';
    echo '<label name="tab" for="tab2_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">MORE INFO</label>';
    echo '<input type="checkbox" class="tab" id="tab2_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon"><i class="fas fa-plus"></i><i class="fas fa-minus"></i></span>';
    echo '<div class="content">';
    echo "<p>" . $parsedown->text(decode_psych_eval(getFieldIfExists($payload, 'nuance_chunk'))) . "</p>";
    echo '</div>';
    echo '</li>';
    echo '<li data-md-content="600">';
    echo '<label name="tab" for="tab3_' . $the_profile_id . '" tabindex="-1" class="tab_lab" role="tab">DATING ANALYSIS</label>';
    echo '<input type="checkbox" class="tab" id="tab3_' . $the_profile_id . '" tabindex="0" />';
    echo '<span class="open-close-icon"><i class="fas fa-plus"></i><i class="fas fa-minus"></i></span>';
    echo '<div class="content">';
    echo "<p>" . $parsedown->text(decode_psych_eval(getFieldIfExists($payload, 'psych_eval'))) . "</p>";
    echo '</div>';
    echo '</li>';
    echo '</ul>';
    echo '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '</td></tr>'; 
    echo '</table><br><br>';
    // echo '</div>';
    }
echo '</div>';




// After the results table has been rendered
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Match Results</title>
    <style>
        /* Existing styles */
    </style>
    <script>
    let map1, map2;
    let markers1 = [], markers2 = [];

    function initMap() {
        const mapOptions = {
            zoom: 4,
            center: { lat: 37.0902, lng: -95.7129 }, // Center of the United States
        };
        map1 = new google.maps.Map(document.getElementById('map1'), mapOptions);
        map2 = new google.maps.Map(document.getElementById('map2'), mapOptions);

        <?php
        if (isset($top_10_matches) && count($top_10_matches) > 0) {
            echo 'clearMarkers(markers1);'; // Clear existing markers

            foreach ($top_10_matches as $profile_id => $distance) {
                $match = array_filter($query_response['result'], function($item) use ($profile_id) {
                    return $item['payload']['profile_id'] == $profile_id;
                });
                $match = array_shift($match);
                $payload = $match['payload'];
                $lat = $payload['location']['lat'];
                $lng = $payload['location']['lon'];
                $firstName = $payload['first-name'];
                $the_profile_id = $payload['profile_id'];

                echo "addMarker($lat, $lng, '$firstName', '$the_profile_id', map1, markers1);";
            }
        }

        if (isset($farthest_matches) && count($farthest_matches) > 0) {
            echo 'clearMarkers(markers2);'; // Clear existing markers

            foreach ($farthest_matches as $profile_id => $distance) {
                $match = array_filter($query_response['result'], function($item) use ($profile_id) {
                    return $item['payload']['profile_id'] == $profile_id;
                });
                $match = array_shift($match);
                $payload = $match['payload'];
                $lat = $payload['location']['lat'];
                $lng = $payload['location']['lon'];
                $firstName = $payload['first-name'];
                $the_profile_id = $payload['profile_id'];

                echo "addMarker($lat, $lng, '$firstName', '$the_profile_id', map2, markers2);";
            }
        }
        ?>
    }

    function addMarker(lat, lng, label, profileId, map, markers) {
    const marker = new google.maps.Marker({
        position: { lat, lng },
        map,
        label,
    });

    // Add a click event handler to the marker.
    marker.addListener('click', function() {
        window.location.href = 'https://kiss-qa.kelleher-international.com/profile/' + profileId;
    });

    markers.push(marker);
}

    function clearMarkers(markers) {
        markers.forEach((marker) => marker.setMap(null));
        markers = [];
    }

    function loadGoogleMapsScript() {
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyDQ_3Z8uKwAnvhGbB3sBZj4TJ7OMo-S4FU&callback=initMap';
        script.async = true;
        document.head.appendChild(script);
    }

    window.addEventListener('load', loadGoogleMapsScript);
</script>
</head>
<body>
    <!-- Existing HTML content -->
    <br><hr><br>
    <h2 style="text-align:center;">Top 10 Closest Matches Map</h2><br>
    <div id="map1" style="height: 600px; width: 100%;"></div><br><hr><br>
    <h2 style="text-align:center;">Good but Farther Matches Map</h2><br>
    <div id="map2" style="height: 600px; width: 100%;"></div>
</body>
</html>