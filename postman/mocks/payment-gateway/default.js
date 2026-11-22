// Local stand-in shaped from the user's example; fields and values are synthetic.
const http = require('http');
const PORT = process.env.PORT || 4500;
http.createServer((req, res) => {
  const pathname = new URL(req.url || '', 'http://localhost').pathname;
  if (req.method !== 'POST' || pathname !== '/api/generateLink') {
    res.writeHead(501, {'Content-Type': 'application/json'});
    return res.end(JSON.stringify({error: 'Not simulated'}));
  }
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', () => {
    const form = new URLSearchParams(body);
    const valid = (req.headers['content-type'] || '').startsWith('application/x-www-form-urlencoded') &&
      form.get('token') === 'simulation-token' && form.get('payee_name') === 'Wire Test' &&
      form.get('transaction_details') === 'Simulation' && form.get('amount') === '10.00' &&
      form.get('type') === 'card' && form.get('limit') === '1' &&
      /^https:\/\/api\.example\.test\/api\/v1\/payment-gateway\/callback\/[a-f0-9-]+\/[A-Za-z0-9]{64}$/.test(form.get('redirect_url') || '');
    res.writeHead(valid ? 200 : 422, {'Content-Type': 'application/json'});
    res.end(JSON.stringify(valid ? {
      status: 200,
      title: 'Payment Link Generated',
      message: 'Synthetic payment link generated.',
      data: {
        link: 'https://gateway.samsam123.name.my/index.html?data=syntheticcheckout',
        id: 42,
        payee_name: 'Wire Test',
        transaction_details: 'Simulation',
        generated_date: '2026-11-16 10:45:41',
        transaction_currency: 'MYR',
        sale_amount: '10.00',
        redirect_url: form.get('redirect_url'),
        limit: '1',
        type: 'card',
        gateway_id: 'simulation-transaction-001',
      },
    } : {error: 'Unexpected request'}));
  });
}).listen(PORT);
