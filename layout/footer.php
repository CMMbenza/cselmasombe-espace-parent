<?php
// /parent/layout/footer.php
declare(strict_types=1);
?>

<!-- Bouton WhatsApp Assistance flottant -->
<style>
#whatsapp-assist {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background-color: #25d35c;
    color: white;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    text-decoration: none;
    z-index: 1000;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

#whatsapp-assist:hover {
    transform: scale(1.15);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
}

#whatsapp-tooltip {
    position: absolute;
    bottom: 70px;
    right: 0;
    background: #333;
    color: #fff;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 13px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

#whatsapp-assist:hover #whatsapp-tooltip {
    opacity: 1;
}
</style>

<a id="whatsapp-assist" href="https://wa.me/243980287578" target="_blank" title="Assistance enligne(Whatsapp)">
    <!-- Icône assistance/service (SVG) -->
    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" viewBox="0 0 24 24">
        <path
            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 
                 10-4.48 10-10S17.52 2 12 2zm.75 15h-1.5v-1.5h1.5V17zm1.35-5.85l-.85.85c-.2.2-.35.45-.35.75v.45h-1.5v-.5c0-.3.15-.55.35-.75l1-1c.2-.2.3-.45.3-.7 0-.55-.45-1-1-1s-1 .45-1 1H9c0-1.65 1.35-3 3-3s3 1.35 3 3c0 .7-.3 1.35-.9 1.9z" />
    </svg>
    <div id="whatsapp-tooltip">Assistance enligne(Whatsapp)</div>
</a>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>