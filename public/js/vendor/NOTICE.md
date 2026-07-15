# Bibliotecas de terceiros vendorizadas

## powerbi-client.min.js

- **Pacote:** [`powerbi-client`](https://www.npmjs.com/package/powerbi-client) (Microsoft)
- **Versão:** 2.23.10
- **Licença:** MIT
- **Origem:** `https://cdn.jsdelivr.net/npm/powerbi-client@2.23.10/dist/powerbi.min.js`
  (espelho jsDelivr do pacote npm oficial — baixado e fixado nesta versão
  em vez de referenciado via CDN em tempo de execução, para não depender da
  disponibilidade externa do jsDelivr em produção).
- **Uso:** renderiza relatórios Power BI no navegador usando o embed token
  gerado no backend pelo modo `secure` (`PowerBiSource`/`PowerBiClient`) —
  ver `public/js/analyticdesign-powerbi.js`.
- **Atualização:** para trocar a versão, baixar o novo `dist/powerbi.min.js`
  do pacote `powerbi-client` na versão desejada e substituir este arquivo.
