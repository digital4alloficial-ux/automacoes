<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/src/Catalog.php';
use Digital4All\{ReadTransport, ReadFailure, CurlReadTransport, Catalog};
final class FakeReadTransport implements ReadTransport {
    public array $calls=[];
    public array $products=[];
    public array $balances=[];
    public ?string $failProducts=null;
    public ?string $failStock=null;
    public function get(string $path,array $query,string $token): array {
        $this->calls[]=[$path,$query,$token];
        if ($path==='/produtos') { if ($this->failProducts) { throw new ReadFailure($this->failProducts); } return $this->products; }
        if ($path==='/estoques/saldos') { if ($this->failStock) { throw new ReadFailure($this->failStock); } return $this->balances; }
        throw new RuntimeException('Unexpected read endpoint');
    }
}
function readFails(callable $fn,string $reason,string $name): void {
    try { $fn(); } catch (ReadFailure $e) { check($e->reason===$reason,$name); return; }
    throw new RuntimeException('FAIL: '.$name);
}
$writerA=new FakeTransport();$writerA->tokens=testTokens('-catalog-a');$serviceA=testService($db,$writerA);
$state=stateOf($serviceA->authorizationUrl($a,'catalog-a'));
$serviceA->callback($a,'catalog-a',['state'=>$state,'code'=>'fixture-code']);
$writerB=new FakeTransport();$writerB->tokens=testTokens('-catalog-b');$serviceB=testService($db,$writerB);
$state=stateOf($serviceB->authorizationUrl($b,'catalog-b'));
$serviceB->callback($b,'catalog-b',['state'=>$state,'code'=>'fixture-code']);
$read=new FakeReadTransport();$read->products=[['id'=>10,'nome'=>'<script>fixture</script>','codigo'=>'SKU-10'],['id'=>11,'nome'=>'Produto sem saldo']];
$read->balances=[['produto'=>['id'=>10],'saldoFisicoTotal'=>2.5,'saldoVirtualTotal'=>-1]];
$catalog=new Catalog($serviceA,$read);$data=$catalog->list($a,2);
check($read->calls[0][0]==='/produtos' && $read->calls[0][1]===['pagina'=>2,'limite'=>20,'criterio'=>2,'tipo'=>'P'],'catálogo usa GET produtos com paginação limitada e ativos');
check($read->calls[1][0]==='/estoques/saldos' && $read->calls[1][1]===['idsProdutos'=>[10,11]],'estoque consulta somente IDs retornados na página da empresa');
check($read->calls[0][2]==='test-access-catalog-a','consulta recebe token da empresa A');
check($data['rows'][0]['physical']===2.5 && $data['rows'][0]['virtual']===-1.0,'preserva saldos fracionários e negativos');
check($data['rows'][1]['physical']===null && $data['rows'][1]['virtual']===null,'saldo ausente não é convertido em zero');
check($data['may_have_next']===false && $data['page']===2,'página incompleta encerra navegação');
$readB=new FakeReadTransport();(new Catalog($serviceB,$readB))->list($b,1);
check($readB->calls[0][2]==='test-access-catalog-b' && $readB->calls[0][2]!==$read->calls[0][2],'empresa B recebe token independente');
check(count($readB->calls)===1,'página vazia não consulta saldos');
$read->failStock='api_forbidden';$data=$catalog->list($a,1);
check(count($data['rows'])===2 && $data['stock_error']==='api_forbidden' && $data['rows'][0]['physical']===null,'escopo de estoque ausente preserva produtos e sinaliza saldos indisponíveis');
$read->failStock=null;$read->balances=[['produto'=>['id'=>999],'saldoFisicoTotal'=>999]];
$data=$catalog->list($a,1);
check($data['stock_error']==='api_response_invalid' && $data['rows'][0]['physical']===null,'saldo de produto não solicitado recusado');
$read->failProducts='api_rate_limited';$before=count($read->calls);
readFails(fn()=>$catalog->list($a,1),'api_rate_limited','429 tratado sem retry automático');
check(count($read->calls)===$before+1,'limite do provedor não inicia novas chamadas');
$read->failProducts=null;$read->products=[['id'=>'10','nome'=>'Inválido']];
readFails(fn()=>$catalog->list($a,1),'api_response_invalid','ID inesperado do provedor recusado');
foreach (['0','-1','1.5','1e2',['1'],'1000000',''] as $invalid) { readFails(fn()=>Catalog::page($invalid),'api_invalid_request','paginação inválida recusada'); }
$before=count($read->calls);readFails(fn()=>$catalog->list($a,0),'api_invalid_request','paginação validada antes de usar tokens');check(count($read->calls)===$before,'requisição inválida não chama transporte');
$url=CurlReadTransport::url('/estoques/saldos',['idsProdutos'=>[10,11]]);
check($url==='https://api.bling.com.br/Api/v3/estoques/saldos?idsProdutos%5B%5D=10&idsProdutos%5B%5D=11','URL oficial e arrays idsProdutos[] serializados corretamente');
readFails(fn()=>CurlReadTransport::url('https://evil.invalid',[]),'api_invalid_request','URL arbitrária bloqueada');
readFails(fn()=>CurlReadTransport::url('/produtos/10',[]),'api_invalid_request','caminho fora da allowlist bloqueado');
foreach([401=>'api_unauthorized',403=>'api_forbidden',429=>'api_rate_limited',500=>'api_unavailable',302=>'api_unavailable'] as $status=>$reason) {
    readFails(fn()=>CurlReadTransport::decode($status,'SENSITIVE PROVIDER BODY'),$reason,'erro HTTP '.$status.' sanitizado');
}
foreach(['not json','{"data":null}','{"data":{"id":1}}',str_repeat('x',1048577)] as $badBody) {
    readFails(fn()=>CurlReadTransport::decode(200,$badBody),'api_response_invalid','corpo inválido/inesperado recusado');
}
check(CurlReadTransport::decode(200,'{"data":[]}')===[],'resposta vazia válida aceita');
$read->products=array_fill(0,21,['id'=>1,'nome'=>'fixture']);readFails(fn()=>$catalog->list($a,1),'api_response_invalid','tamanho excessivo da página recusado');
