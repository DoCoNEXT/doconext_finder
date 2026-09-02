<template>
  <NcContent :app-name="appId">
    <NcAppContent>
      <h2 class="app-title">{{ PRODUCT_NAME }}</h2>
      <FileSearch />
    </NcAppContent>
  </NcContent>
</template>

<script setup lang="ts">
import { NcContent, NcAppContent } from '@nextcloud/vue'
import { APP_ID, PRODUCT_NAME } from './constants'
import FileSearch from './components/FileSearch.vue'

const appId = APP_ID
</script>

<style scoped lang="scss">
.app-title {
	max-width: 1100px;
	margin: 0 auto;
	padding: 24px 24px 0;
	font-size: 22px;
	font-weight: 700;
}
</style>
