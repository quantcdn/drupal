#!/bin/bash
# Regression matrix for the multi-domain branch.
#
# Runs every publishing path in both shapes a customer can have:
#   single  - no domain module, one project, no --uri (how most sites run)
#   multi   - domain module, two domains, two projects, --uri per domain
#
# Prints a pass/fail line per case. Reports which project each push reached,
# because publishing to the wrong project is the failure that matters.

# The ddev project root, i.e. wherever mock-quant-api.py is writing its log.
# Override with QUANT_REGRESSION_LOG when running from elsewhere.
LOG="${QUANT_REGRESSION_LOG:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/requests.jsonl}"
PASS=0
FAIL=0

reset_log() { : > "$LOG"; }

# pushes_to <project> -> count of content pushes addressed to that project
pushes_to() {
  python3 -c "
import json,sys
n=0
for line in open('$LOG'):
    line=line.strip()
    if not line: continue
    r=json.loads(line)
    if r['pushed_url'] and r['path']=='/v1' and r['quant_project']=='$1': n+=1
print(n)"
}

total_pushes() {
  python3 -c "
import json
n=0
for line in open('$LOG'):
    line=line.strip()
    if not line: continue
    r=json.loads(line)
    if r['pushed_url'] and r['path']=='/v1': n+=1
print(n)"
}

# Everything the API received that was NOT addressed to the given project.
# Exact counts move with the fixture; "all of it went to the right place, and
# none of it went anywhere else" is the property that actually matters.
pushes_elsewhere() {
  python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r['quant_project'] != '$1']))"
}

check_routed() {
  local name="$1" project="$2"
  local mine elsewhere
  mine=$(pushes_to "$project")
  elsewhere=$(pushes_elsewhere "$project")
  if [ "$mine" -gt 0 ] && [ "$elsewhere" -eq 0 ]; then
    echo "  PASS  $name ($mine to $project, 0 elsewhere)"
    PASS=$((PASS+1))
  else
    echo "  FAIL  $name ($mine to $project, $elsewhere elsewhere)"
    FAIL=$((FAIL+1))
  fi
}

# Unpublish sends its target in a header, not the body.
unpublishes_to() {
  python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r['path']=='/v1/unpublish' and r['quant_project']=='$1']))"
}

unpublishes_total() {
  python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r['path']=='/v1/unpublish']))"
}

# Creates a translated node, deletes it, and leaves the calls in the log.
delete_translated_node() {
  local uri_arg="$1"
  local nid
  nid=$(ddev drush $uri_arg php:eval '
use Drupal\node\Entity\Node;
$n = Node::create(["type"=>"page","title"=>"regression delete","status"=>1]);
$n->save();
$n->addTranslation("fr", ["title"=>"regression delete fr","status"=>1])->save();
print $n->id();' 2>/dev/null | tr -d "[:space:]")
  reset_log
  ddev drush $uri_arg php:eval "\\Drupal\\node\\Entity\\Node::load($nid)->delete();" >/dev/null 2>&1
}

check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    echo "  PASS  $name (got $actual)"
    PASS=$((PASS+1))
  else
    echo "  FAIL  $name (expected $expected, got $actual)"
    FAIL=$((FAIL+1))
  fi
}

# Always start from a single-site shape, whatever the last run left behind.
teardown_domains() {
  ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("domain"); $s->delete($s->loadMultiple());' >/dev/null 2>&1
  ddev drush pmu domain_config -y >/dev/null 2>&1
  ddev drush pmu domain -y >/dev/null 2>&1
  ddev drush cr >/dev/null 2>&1
}

has_path() {
  python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print('yes' if any(r['pushed_url']=='$1' for r in rows) else 'no')"
}

setup_domains() {
  ddev drush en domain domain_config -y >/dev/null 2>&1
  ddev drush php:eval '
use Drupal\domain\Entity\Domain;
$s = \Drupal::entityTypeManager()->getStorage("domain");
foreach ([["clienta_ddev_site","clienta.ddev.site:33000","Client A",1,1],["clientb_ddev_site","clientb.ddev.site:33000","Client B",2,0]] as [$id,$host,$name,$w,$def]) {
  if (!$s->load($id)) {
    Domain::create(["id"=>$id,"hostname"=>$host,"name"=>$name,"scheme"=>"http","status"=>1,"weight"=>$w,"is_default"=>$def])->save();
  }
}
$base = \Drupal::service("config.storage");
foreach (["clienta_ddev_site"=>["PROJECT-CLIENT-A","token-a","clienta.ddev.site:33000"],"clientb_ddev_site"=>["PROJECT-CLIENT-B","token-b","clientb.ddev.site:33000"]] as $id=>[$p,$t,$h]) {
  // Domain 3.x reads a config collection; Domain 2.x reads a prefixed config
  // object. Write both so this runs on either supported line.
  $c = $base->createCollection("domain.$id");
  $c->write("quant_api.settings", ["api_project"=>$p,"api_token"=>$t]);
  $c->write("quant.settings", ["host_domain"=>$h]);
  $base->write("domain.config.$id.quant_api.settings", ["api_project"=>$p,"api_token"=>$t]);
  $base->write("domain.config.$id.quant.settings", ["host_domain"=>$h]);
}' >/dev/null 2>&1
  ddev drush cr >/dev/null 2>&1
}

trap teardown_domains EXIT

echo "================ SINGLE SITE (no domain module) ================"
teardown_domains

reset_log
ddev drush quant:seed-queue >/dev/null 2>&1
ddev drush quant:run-queue --threads=3 >/dev/null 2>&1
check_routed "seed + run-queue, no --uri" SINGLE-SITE

reset_log
ddev drush cron >/dev/null 2>&1
check_routed "cron, no --uri" SINGLE-SITE

reset_log
ddev drush --uri=http://quant-domain-poc.ddev.site:33000 quant:seed-queue >/dev/null 2>&1
ddev drush --uri=http://quant-domain-poc.ddev.site:33000 quant:run-queue --threads=2 >/dev/null 2>&1
check_routed "seed + run-queue, with --uri" SINGLE-SITE

reset_log
ddev drush php:eval '\Drupal\quant\Seed::seedNode(\Drupal\node\Entity\Node::load(1), "en");' >/dev/null 2>&1
check_routed "direct seedNode" SINGLE-SITE

reset_log
ddev drush tome:static -y >/dev/null 2>&1
ddev drush quant:tome:deploy >/dev/null 2>&1
check_routed "tome deploy, no --uri" SINGLE-SITE

# quant_webform alters webform's libraries, so a webform page still has to
# render and publish with it enabled.
reset_log
ddev drush php:eval '(new \Drupal\quant\Plugin\QueueItem\RouteItem(["route" => "/form/contact"]))->send();' >/dev/null 2>&1
check_routed "webform page publishes" SINGLE-SITE

# Deleting withdraws pages from the edge, so a misdirected delete takes down
# a live page rather than merely adding a wrong one.
delete_translated_node ""
check "delete withdraws every language" "$(unpublishes_to SINGLE-SITE)" "2"
check "  nothing withdrawn elsewhere" "$(unpublishes_total)" "2"

echo
echo "================ MULTI DOMAIN (two domains) ================"
ddev drush en domain domain_config -y >/dev/null 2>&1
ddev drush php:eval '
use Drupal\domain\Entity\Domain;
$s = \Drupal::entityTypeManager()->getStorage("domain");
foreach ([["clienta_ddev_site","clienta.ddev.site:33000","Client A",1,1],["clientb_ddev_site","clientb.ddev.site:33000","Client B",2,0]] as [$id,$host,$name,$w,$def]) {
  if (!$s->load($id)) {
    Domain::create(["id"=>$id,"hostname"=>$host,"name"=>$name,"scheme"=>"http","status"=>1,"weight"=>$w,"is_default"=>$def])->save();
  }
}
$base = \Drupal::service("config.storage");
foreach (["clienta_ddev_site"=>["PROJECT-CLIENT-A","token-a","clienta.ddev.site:33000"],"clientb_ddev_site"=>["PROJECT-CLIENT-B","token-b","clientb.ddev.site:33000"]] as $id=>[$p,$t,$h]) {
  // Domain 3.x reads a config collection; Domain 2.x reads a prefixed config
  // object. Write both so this runs on either supported line.
  $c = $base->createCollection("domain.$id");
  $c->write("quant_api.settings", ["api_project"=>$p,"api_token"=>$t]);
  $c->write("quant.settings", ["host_domain"=>$h]);
  $base->write("domain.config.$id.quant_api.settings", ["api_project"=>$p,"api_token"=>$t]);
  $base->write("domain.config.$id.quant.settings", ["host_domain"=>$h]);
}' >/dev/null 2>&1
ddev drush cr >/dev/null 2>&1

for D in clienta clientb; do
  UP=$(echo "$D" | tr 'a-z' 'A-Z' | sed 's/CLIENT/CLIENT-/')
  reset_log
  ddev drush --uri="http://$D.ddev.site:33000" quant:seed-queue >/dev/null 2>&1
  ddev drush --uri="http://$D.ddev.site:33000" quant:run-queue --threads=2 >/dev/null 2>&1
  check_routed "seed + run-queue as $D" "PROJECT-$UP"

  reset_log
  ddev drush --uri="http://$D.ddev.site:33000" cron >/dev/null 2>&1
  check_routed "cron as $D" "PROJECT-$UP"

  reset_log
  ddev drush --uri="http://$D.ddev.site:33000" quant:tome:deploy >/dev/null 2>&1
  check_routed "tome deploy as $D" "PROJECT-$UP"

  delete_translated_node "--uri=http://$D.ddev.site:33000"
  check "delete as $D withdraws from PROJECT-$UP only" "$(unpublishes_to "PROJECT-$UP")" "2"
  check "  nothing withdrawn elsewhere" "$(unpublishes_total)" "2"
done

echo
echo "  -- guard cases --"
reset_log
ddev drush quant:seed-queue >/dev/null 2>&1
ddev drush quant:run-queue --threads=2 >/dev/null 2>&1
check "multi-domain, no --uri, no content published" "$(total_pushes)" "0"
# Redirects reach the same project, so the guard has to stop those too.
check "  no redirects published either" "$(python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r['path']=='/v1/redirect']))")" "0"

# The destructive path matters most: a delete on an unrecognised host would
# withdraw a live page from whichever project the fallback landed on.
delete_translated_node ""
check "  no pages withdrawn either" "$(unpublishes_total)" "0"

echo
echo "================ MULTILINGUAL ================"
teardown_domains

# Paths and redirects are built from language prefixes, and getPathPrefix()
# already carries its leading slash. Concatenating another one published
# redirects at //fr/node/1 for every translation.
reset_log
ddev drush php:eval '\Drupal\quant\Seed::seedNode(\Drupal\node\Entity\Node::load(1), "fr");' >/dev/null 2>&1
ddev drush php:eval '\Drupal\quant\Seed::seedNode(\Drupal\node\Entity\Node::load(1), "de");' >/dev/null 2>&1

malformed=$(python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r['pushed_url'] and '//' in r['pushed_url']]))")
check "no malformed // paths in any push" "$malformed" "0"

check "french translation published at its alias" "$(has_path /fr/page-une)" "yes"
check "german translation published at its alias" "$(has_path /de/seite-eins)" "yes"
check "prefixed internal path redirect created" "$(has_path /fr/node/1)" "yes"

echo
echo "================ MULTILINGUAL x MULTI DOMAIN ================"
# The shape the multi-client customers actually run: several domains, each
# publishing to its own project, every page in several languages. A leak here
# puts one client's translated page on another client's site.
setup_domains

for D in clienta clientb; do
  UP=$(echo "$D" | tr 'a-z' 'A-Z' | sed 's/CLIENT/CLIENT-/')

  reset_log
  ddev drush --uri="http://$D.ddev.site:33000" quant:seed-queue >/dev/null 2>&1
  ddev drush --uri="http://$D.ddev.site:33000" quant:run-queue --threads=2 >/dev/null 2>&1
  check_routed "seed as $D, all languages" "PROJECT-$UP"

  for path in /page-one /fr/page-une /de/seite-eins; do
    check "  $path reached PROJECT-$UP" "$(has_path "$path")" "yes"
  done

  check "  search records only in PROJECT-$UP" "$(python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
print(len([r for r in rows if r.get('has_search_record') and r['quant_project'] != 'PROJECT-$UP']))")" "0"

  delete_translated_node "--uri=http://$D.ddev.site:33000"
  check "delete as $D withdraws all languages from PROJECT-$UP" "$(unpublishes_to "PROJECT-$UP")" "2"
  check "  nothing withdrawn from another project" "$(unpublishes_total)" "2"
done

echo
echo "================ PURGE FAN-OUT ================"
# A page served by several domains must be purged on each, addressed to that
# domain's own project. The resolution reads the override storage, which the
# Domain module lays out differently between its 2.x and 3.x lines, so this
# is the case that silently regressed once already.
setup_domains

# The traffic registry only records requests carrying a Quant token, and the
# crawl only sends one when content drafts are enabled.
ddev drush php:eval '\Drupal::configFactory()->getEditable("quant.settings")->set("disable_content_drafts", FALSE)->save(); \Drupal::database()->truncate("purge_queuer_quant")->execute();' >/dev/null 2>&1
ddev drush cr >/dev/null 2>&1

for D in clienta clientb; do
  ddev drush --uri="http://$D.ddev.site:33000" quant:seed-queue >/dev/null 2>&1
  ddev drush --uri="http://$D.ddev.site:33000" quant:run-queue --threads=2 >/dev/null 2>&1
done

registered=$(ddev drush php:eval 'print \Drupal::database()->select("purge_queuer_quant","q")->condition("url","/node/2")->countQuery()->execute()->fetchField();' 2>/dev/null | tr -d "[:space:]")
check "the page is registered on both domains" "$registered" "2"

ddev drush quant:clear-queue >/dev/null 2>&1
stamps=$(ddev drush --uri=http://clienta.ddev.site:33000 php:eval '
\Drupal\Core\Cache\Cache::invalidateTags(["node:2"]);
$q = \Drupal\quant\QuantQueueFactory::getInstance()->get("quant_seed_worker");
$out = [];
while ($i = $q->claimItem()) { $out[] = (string) $i->data->getTargetProject(); $q->deleteItem($i); }
sort($out);
print implode(",", $out);' 2>/dev/null | tr -d "[:space:]")

# Both projects, each once. Before the fix this was the current domain twice.
check "each domain gets its own project stamped" "$stamps" "PROJECT-CLIENT-A,PROJECT-CLIENT-B"

ddev drush php:eval '\Drupal::configFactory()->getEditable("quant.settings")->set("disable_content_drafts", TRUE)->save();' >/dev/null 2>&1

echo
echo "================ STALE DOMAIN ACCESS GRANTS ================"
# Domain Access decides which domain serves which node through node grants.
# Enabling it leaves them stale until someone rebuilds, and until then every
# domain serves every page. A seed then collects all of it and publishes one
# client's content into another client's project, correctly routed, so
# nothing else reports a problem. Only runs where domain_access is installed.
ddev drush en domain_access -y >/dev/null 2>&1
# The domain fields are attached on install; their definitions have to be
# visible before content can be assigned to a domain.
ddev drush cr >/dev/null 2>&1

if ddev drush pm:list --status=enabled 2>/dev/null | grep -qi "domain_access"; then
  # Content has to belong to a domain before straying content is meaningful.
  # Nodes are named "A page 1", "B page 1" and so on by the fixture.
  ddev drush php:eval '
$map = ["A" => "clienta_ddev_site", "B" => "clientb_ddev_site"];
foreach (\Drupal::entityTypeManager()->getStorage("node")->loadMultiple() as $node) {
  if (!preg_match("/^([AB]) page /", $node->label(), $m)) { continue; }
  if (!$node->hasField("field_domain_access")) { continue; }
  $node->set("field_domain_access", [["target_id" => $map[$m[1]]]]);
  $node->set("field_domain_all_affiliates", 0);
  $node->save();
}
node_access_rebuild();' >/dev/null 2>&1
  ddev drush cr >/dev/null 2>&1

  reset_log

  # Replicate the unrebuilt state exactly: one fallback row granting all.
  ddev drush php:eval '
$db = \Drupal::database();
$db->truncate("node_access")->execute();
$db->insert("node_access")->fields(["nid"=>0,"langcode"=>"","fallback"=>1,"gid"=>0,"realm"=>"all","grant_view"=>1,"grant_update"=>0,"grant_delete"=>0])->execute();
\Drupal::moduleHandler()->loadInclude("node","module");
node_access_needs_rebuild(TRUE);' >/dev/null 2>&1
  ddev drush cr >/dev/null 2>&1

  ddev drush --uri=http://clienta.ddev.site:33000 quant:seed-queue >/dev/null 2>&1
  ddev drush --uri=http://clienta.ddev.site:33000 quant:run-queue --threads=3 >/dev/null 2>&1

  foreign=$(python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
pub=[r['pushed_url'] for r in rows if r.get('path')=='/v1' and r.get('pushed_url')]
print(len([u for u in pub if u.startswith('/b-')]))")
  check "no other domain's pages published while grants are stale" "$foreign" "0"

  own=$(python3 -c "
import json
rows=[json.loads(l) for l in open('$LOG') if l.strip()]
pub=[r['pushed_url'] for r in rows if r.get('path')=='/v1' and r.get('pushed_url')]
print(len([u for u in pub if u.startswith('/a-')]))")
  if [ "$own" -gt 0 ]; then
    echo "  PASS  its own pages still publish (got $own)"
    PASS=$((PASS+1))
  else
    echo "  FAIL  its own pages still publish (got $own)"
    FAIL=$((FAIL+1))
  fi

  # Rebuild, and confirm publishing returns to normal with no refusals.
  ddev drush php:eval 'node_access_rebuild();' >/dev/null 2>&1
  ddev drush cr >/dev/null 2>&1
  reset_log
  refusals=$(ddev drush --uri=http://clienta.ddev.site:33000 quant:seed-queue >/dev/null 2>&1; ddev drush --uri=http://clienta.ddev.site:33000 quant:run-queue --threads=3 2>&1 | grep -ci "Domain Access assigns")
  check "no refusals once grants are rebuilt" "$refusals" "0"
else
  echo "  SKIP  domain_access is not installed"
fi

echo
echo "================ RESULT ================"
echo "  passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
