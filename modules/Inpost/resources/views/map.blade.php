<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wybierz paczkomat InPost</title>
    <link rel="stylesheet" href="https://geowidget.inpost.pl/inpost-geowidget.css"/>
    <script src="https://geowidget.inpost.pl/inpost-geowidget.js" defer></script>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; }
        inpost-geowidget { display: block; width: 100%; height: 100vh; min-height: 500px; }
    </style>
</head>
<body>
    <inpost-geowidget
        onpoint="inpostPointSelected"
        token="{{ $token }}"
        language="pl"
        config="parcelcollect"
    ></inpost-geowidget>

    <script>
        function inpostPointSelected(point) {
            if (!point || !window.opener) return;
            var name = point.name || point.point_name || point.id || point.code || '';
            if (typeof name === 'object') name = name.name || name.point_name || '';
            name = String(name || '').trim().toUpperCase();
            var code = (name.match(/[A-Z]{3}[0-9]{2}[A-Z0-9]?/) || [name])[0];
            if (code) {
                var addr = '';
                if (point.address) {
                    addr = [point.address.line1, point.address.line2].filter(Boolean).join(', ');
                }
                if (!addr && point.address_details) {
                    var d = point.address_details;
                    addr = [d.street, d.building_number, d.city, d.post_code].filter(Boolean).join(', ');
                }
                if (!addr && point.location_description) addr = point.location_description;
                window.opener.postMessage({
                    type: 'inpost-point-selected',
                    name: code,
                    address: addr,
                    location: point.location || point
                }, window.location.origin);
                window.close();
            }
        };
        document.addEventListener('onpointselect', function(e) {
            var point = e.detail || e.details;
            if (point) inpostPointSelected(point);
        });
    </script>
</body>
</html>
