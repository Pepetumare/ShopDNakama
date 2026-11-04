<section x-data="designAssistance(@js(['enabled' => (bool) old('design_assistance', false), 'notes' => old('design_assistance_notes', ''), 'products' => array_values(array_filter(config('custom_orders.design_assistance_products'), fn ($type) => $type !== '3d_print'))]))" x-effect="syncAssistanceProduct(product)">
    <template x-if="assistanceEligible"><div class="cp-assistance">
        <h3>¿Quieres que nuestro equipo adapte tu diseño? <span>+<x-store.price :amount="config('custom_orders.design_assistance_price')"/></span></h3>
        <p>Podemos ajustar, distribuir o componer tu imagen para que se adapte mejor al producto.</p>
        <label class="cp-assistance-check"><input type="checkbox" name="design_assistance" value="1" :checked="assistanceEnabled" @change="toggleAssistance($event.target.checked)"> Quiero que el equipo adapte mi diseño (+<x-store.price :amount="config('custom_orders.design_assistance_price')"/>)</label>
        <input type="hidden" name="design_assistance_notes" :value="assistanceNotes" :disabled="!assistanceEnabled">
        <div x-show="assistanceEnabled">
            <p class="cp-assistance-notes" x-text="assistanceNotes"></p>
            <button type="button" class="shop-button green" @click="openAssistance()">Editar indicaciones</button>
            <p>Esta vista previa es referencial. Nuestro equipo realizará los ajustes indicados antes de producir el producto.</p>
        </div>
        <dialog class="cp-assistance-dialog" x-ref="assistanceDialog" aria-labelledby="assistance-title" aria-describedby="assistance-description" @cancel.prevent="cancelAssistance()" @close="if (assistanceOpen) cancelAssistance()">
            <h2 id="assistance-title">Cuéntanos cómo quieres tu diseño</h2>
            <p id="assistance-description">Describe con el mayor detalle posible cómo quieres que adaptemos la imagen al producto.</p>
            <ul><li>Quiero el personaje principal al centro.</li><li>Usen el fondo para completar toda la taza.</li><li>Quiero el logo pequeño arriba y la imagen debajo.</li><li>No cortar las caras.</li><li>Pueden agregar un fondo similar a los colores de la imagen.</li></ul>
            <label>Indicaciones para el equipo<textarea x-ref="assistanceText" x-model="assistanceDraft" :disabled="!assistanceOpen" :required="assistanceOpen" minlength="10" maxlength="2000" rows="6" @input="$event.target.setCustomValidity('')"></textarea></label>
            <p>Entre 10 y 2000 caracteres. Servicio adicional: +<x-store.price :amount="config('custom_orders.design_assistance_price')"/> por solicitud.</p>
            <div class="cp-buttons"><button type="button" @click="cancelAssistance()">Cancelar</button><button type="button" @click="saveAssistance()">Guardar indicaciones</button></div>
        </dialog>
    </div></template>
</section>
