<?php

require_once(__DIR__.'/vendor/autoload.php');

use DMS\Service\Meetup\MeetupKeyAuthClient;
use Symfony\Component\Yaml\Yaml;
use Neoxygen\NeoClient\ClientBuilder;

$skipSchemaSetup = false;
$dropDbOnInit = false;
if (!isset($argv[1]) || empty($argv[1])) {
    throw new \InvalidArgumentException('You need to pass the event ID as argument : php import.php 12345678');
}
if (isset($argv[2]) && true == (bool) $argv[2]) {
    $skipSchemaSetup = true;
}
if (isset($argv[3]) && (bool) $argv[3] == true) {
    $dropDbOnInit = true;
}
$eventId = (int) $argv[1];
$config = YAML::parse(file_get_contents(__DIR__.'/config.yml'));

$meetupClient = MeetupKeyAuthClient::factory(array('key' => $config['meetup_api_key']));
$neoClient = ClientBuilder::create()
    ->addConnection(
        'default',
        $config['neo4j_scheme'],
        $config['neo4j_host'],
        $config['neo4j_port'],
        true,
        $config['neo4j_user'],
        $config['neo4j_password']
    )
    ->setAutoFormatResponse(true)
    ->build();

// Creating Schema Indexes And Constraints
if (!$skipSchemaSetup) {
    $neoClient->createUniqueConstraint('Event', 'id');
    $neoClient->createUniqueConstraint('Member', 'id');
    $neoClient->createUniqueConstraint('Topic', 'id');
    $neoClient->createUniqueConstraint('Country', 'code');
    $neoClient->createIndex('City', 'name');
} else {
    echo 'Skipping Schema Creation' . "\n";
}

if ($dropDbOnInit) {
    echo 'Dropping DB' . "\n";
    $neoClient->sendCypherQuery('MATCH (n) OPTIONAL MATCH (n)-[r]-() DELETE r,n');
}

// Get Event Informations
$event = $meetupClient->getEvent(array('id' => $eventId));
$eventName = $event['name'];
$eventDesc = $event['description'];
$eventUrl = $event['event_url'];
$groupUrl = $event['group']['urlname'];

// Inserting Event Into Neo4j

$query = 'MERGE (event:Event {id: {event_id}})
ON CREATE SET event.name = {event_name}, event.description = {event_desc}, event.url = {event_url}';
$p = [
    'event_id' => $eventId,
    'event_name' => $eventName,
    'event_desc' => $eventDesc,
    'event_url' => $eventUrl
];
$neoClient->sendCypherQuery($query, $p);


// Get Meetup Group Informations
$groups = $meetupClient->getGroups(array('group_urlname' => $groupUrl));
$group = $groups->current();
$groupId = $group['id'];
$groupName = $group['name'];
$groupDesc = $group['description'];
$groupCountry = strtoupper($group['country']);
$groupTZ = $group['timezone'];
$groupCity = ucfirst($group['city']);
$groupTopics = $group['topics'];
$groupOrganizer = $group['organizer'];

// Inserting Meetup Group Informations

$query = 'MATCH (event:Event {id: {event_id}})
MERGE (g:Group {id: {group_id}})
ON CREATE SET g.name = {group_name}, g.description = {group_desc}, g.url = {group_url}
MERGE (g)-[:ORGANISE_EVENT]->(event)
MERGE (country:Country {code: {country}})
MERGE (city:City {name: {city}})
MERGE (g)-[:GROUP_IN_CITY]->(city)-[:IN_COUNTRY]->(country)
WITH g
UNWIND {topics} as topic
MERGE (t:Topic {id: topic.id})
ON CREATE SET t.name = topic.name
MERGE (t)-[:TAGS_GROUP]->(g)';
$p = [
    'event_id' => $eventId,
    'group_id' => $groupId,
    'group_name' => $groupName,
    'group_desc' => $groupDesc,
    'group_url' => $groupUrl,
    'country' => $groupCountry,
    'city' => $groupCity,
    'topics' => $groupTopics
];
$neoClient->sendCypherQuery($query, $p);

// Inserting Meetup Group's Organiser

$query = 'MATCH (group:Group {id: {group_id}})
MERGE (m:Member {id: {member_id}})
ON CREATE SET m.name = {member_name}
MERGE (m)-[:ORGANISE_GROUP]->(group)';
$p = [
    'member_id' => $groupOrganizer['member_id'],
    'member_name' => $groupOrganizer['name'],
    'group_id' => $groupId
];
$neoClient->sendCypherQuery($query, $p);

// Get Group's Members
$groupMembers = [];
$members = $meetupClient->getMembers(array('group_id' => $groupId));
foreach ($members as $member) {
    $m = [
        'id' => (int) $member['id'],
        'name' => $member['name'],
        'country' => strtoupper($member['country']),
        'city' => ucfirst($member['city']),
        'topics' => $member['topics'],
        'joined_time' => $member['joined'],
        'avatar' => isset($member['photo']['thumb_link']) ?: null
    ];
    $groupMembers[$m['id']] = $m;
}

// Get Member's Groups

foreach ($members as $member) {
    $mgroups = $meetupClient->getGroups(array('member_id' => $member['id']));
    foreach ($mgroups as $g) {
        $groupMembers[$member['id']]['groups'][] = $g;
    }
    usleep(50000);
}

// Inserting Group's Members and Groups they belong to
foreach ($members as $member) {
    //Inserting the member
    echo 'Inserting Member "' . $member['name'] . '"' . "\n";
    $query = 'MERGE (m:Member {id: {member}.id })
    SET m.name = {member}.name, m.avatar = {member}.avatar, m.joined_time = {member}.joined_time
    MERGE (city:City {name: {member}.city})
    MERGE (country:Country {code: {member}.country})
    MERGE (m)-[:LIVES_IN]->(city)
    MERGE (city)-[:IN_COUNTRY]->(country)';
    $p = ['member' => $member];
    $neoClient->sendCypherQuery($query, $p);

    //Inserting Groups the Member Belongs To
    $query = 'MATCH (m:Member {id: {member_id}})
    UNWIND {groups} as group
    MERGE (g:Group {id: group.id})
    SET g.name = group.name, g.description = group.description
    MERGE (o:Member {id: group.organizer.member_id})
    ON CREATE SET o.name = group.organizer.name
    MERGE (city:City {name: group.city})
    MERGE (cty:Country {code: upper(group.country)})
    MERGE (g)-[:GROUP_IN_CITY]->(city)
    MERGE (city)-[:IN_COUNTRY]->(cty)
    MERGE (m)-[:MEMBER_OF]->(g)
    FOREACH (topic IN group.topics |
    MERGE (t:Topic {id: topic.id})
    ON CREATE SET t.name = topic.name
    MERGE (t)-[:TAGS_GROUP]->(g))';
    $p = ['groups' => $groupMembers[$member['id']]['groups'], 'member_id' => $member['id']];
    echo 'Inserting groups for Member "' . $member['name'] . '"' . "\n";
    $neoClient->sendCypherQuery($query, $p);
}

// GetRsvps

$response = $meetupClient->getRSVPs(array('event_id' => $eventId));
$rsvps = [];
foreach ($response as $responseItem) {
    $rsvps[$responseItem['response']][] = [
        'id' => $responseItem['rsvp_id'],
        'member_id' => $responseItem['member']['member_id']
    ];
}
// Inserting Event RSVPS
$query = 'MATCH (e:Event {id: {event_id}})
UNWIND {rsvps}.yes as rsvp
MATCH (m:Member {id: rsvp.member_id})
MERGE (m)-[:PARTICIPATE {rsvp_id: rsvp.id}]->(e)
WITH e
UNWIND {rsvps}.no as rsvp
MATCH (m:Member {id: rsvp.member_id})
MERGE (m)-[:DECLINED {rsvp_id: rsvp.id}]->(e)';
$p = [
    'event_id' => $eventId,
    'rsvps' => $rsvps
];
echo 'Inserting RSVPs' . "\n";
$neoClient->sendCypherQuery($query, $p);
echo 'Import Done, Enjoy !' . "\n";