<?php
/** Real repair worker regression. The isolated large-archive fixture supplies 25 1 MiB articles. */
use Drupal\ttd_topics\Service\ContentUrlRepairService as Repair;
if (TOPICALBOOST_API_ENDPOINT !== 'http://api:3000/api/topicalboost' || \Drupal::request()->getHost() !== 'drupal.example.test') throw new RuntimeException('Isolated fixture required.');
ini_set('memory_limit', '128M');
$db = \Drupal::database();
if ((int) $db->query('SELECT COUNT(*) FROM node WHERE nid BETWEEN 100001 AND 200000')->fetchField() !== 100000) throw new RuntimeException('Large fixture required.');
\Drupal::configFactory()->getEditable('ttd_topics.settings')->set('enabled_content_types',['article'])->set('topicalboost_api_key','e2e-drupal-key')->set('site_base_url','https://drupal.example.test')->save();
\Drupal::state()->set('ttd_topics.content_url_repair.disabled',FALSE);
\Drupal::state()->set(Repair::ENABLED,TRUE);
$db->query("INSERT OR IGNORE INTO node__field_ttd_last_analyzed(bundle,deleted,entity_id,revision_id,langcode,delta,field_ttd_last_analyzed_value) SELECT 'article',0,nid,vid,'en',0,'2026-10-01T00:00:00' FROM node WHERE nid BETWEEN 100001 AND 100025");
if ((int) $db->query('SELECT COUNT(*) FROM node__body WHERE entity_id BETWEEN 100001 AND 100025 AND length(body_value)=1048576')->fetchField() !== 25) throw new RuntimeException('Large article bodies required.');
for ($id=100001;$id<=100025;$id++) \Drupal::httpClient()->post('http://api:3000/fixture/seed',['json'=>['platform'=>'drupal','postId'=>$id,'url'=>'https://drupal.example.test/old/'.$id,'status'=>'publish']]);
$repair=\Drupal::service('ttd_topics.content_url_repair');
$context=new ReflectionMethod($repair,'context');$context->setAccessible(TRUE);
$state=['context'=>$context->invoke($repair),'cursor'=>100000,'end'=>100025,'status'=>'pending','next_at'=>0,'repaired'=>0,'metadata_updated'=>0,'unchanged'=>0,'skipped'=>0,'failed_batches'=>0,'failures'=>0,'batches'=>0,'requests'=>0];
\Drupal::state()->set(Repair::STATE,$state);
$db->delete('advancedqueue')->condition('type',Repair::JOB)->execute();
$repair->queueIfDue();
$queue=\Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(Repair::QUEUE);
for($batch=0;$batch<2;$batch++) {
 $state=\Drupal::keyValue('state')->get(Repair::STATE);$state['next_at']=0;\Drupal::state()->set(Repair::STATE,$state);
 $db->update('advancedqueue')->fields(['available'=>time()-1])->condition('type',Repair::JOB)->condition('state','queued')->execute();
 $job=$queue->getBackend()->claimJob();if(!$job)throw new RuntimeException('Expected repair job.');
 $result=\Drupal::service('advancedqueue.processor')->processJob($job,$queue);
 if($result->getState()!=='success')throw new RuntimeException($result->getMessage());
}
$state=\Drupal::keyValue('state')->get(Repair::STATE);
if($state['status']!=='complete'||$state['repaired']!==25||$state['requests']!==1)throw new RuntimeException('Large article repair did not complete.');
if(memory_get_peak_usage(TRUE)>64*1024*1024)throw new RuntimeException('Large article metadata exceeded the 64 MiB budget. Peak: '.(memory_get_peak_usage(TRUE)/1024/1024).' MiB.');
$report=['platform'=>'drupal','archive_records'=>100000,'article_bytes'=>1048576,'articles'=>25,'php_limit_bytes'=>128*1024*1024,'php_peak_bytes'=>memory_get_peak_usage(TRUE),'state'=>$state];
file_put_contents('/evidence/drupal-worker-memory.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report,JSON_PRETTY_PRINT),"\nPASS: Real Drupal queue repairs 25 large articles and completes under 128 MiB.\n";
